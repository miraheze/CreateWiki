<?php

namespace Miraheze\CreateWiki\RequestWiki;

use JobSpecification;
use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use Miraheze\CreateWiki\Jobs\CreateWikiJob;
use Miraheze\CreateWiki\Jobs\RequestWikiAIJob;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use RuntimeException;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

class WikiRequest {

	private Config $config;
	private IDatabase $dbw;

	private WikiManagerFactory $wikiManagerFactory;

	public User $requester;

	public string $dbname;
	public string $description;
	public string $language;
	public ?int $private;
	public string $sitename;
	public string $url;
	public string $category;
	public string $timestamp;
	public int $bio;
	public ?string $purpose = null;

	private ?int $id = null;
	private string $status = 'inreview';
	private array $comments = [];
	private array $involvedUsers = [];
	private int $visibility = 0;

	public function __construct( ?int $id ) {
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );
		$this->wikiManagerFactory = MediaWikiServices::getInstance()->get( 'WikiManagerFactory' );

		$connectionProvider = MediaWikiServices::getInstance()->getConnectionProvider();
		$this->dbw = $connectionProvider->getPrimaryDatabase(
			$this->config->get( 'CreateWikiGlobalWiki' )
		);

		$userFactory = MediaWikiServices::getInstance()->getUserFactory();

		$dbRequest = $this->dbw->newSelectQueryBuilder()
			->select( '*' )
			->from( 'cw_requests' )
			->where( [ 'cw_id' => $id ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $dbRequest ) {
			$this->id = $dbRequest->cw_id;
			$this->dbname = $dbRequest->cw_dbname;
			$this->language = $dbRequest->cw_language;
			$this->private = $dbRequest->cw_private;
			$this->sitename = $dbRequest->cw_sitename;
			$this->url = $dbRequest->cw_url;
			$this->category = $dbRequest->cw_category;
			$this->requester = $userFactory->newFromId( $dbRequest->cw_user );
			$this->status = $dbRequest->cw_status;
			$this->timestamp = $dbRequest->cw_timestamp;
			$this->visibility = (int)$dbRequest->cw_visibility;
			$this->bio = $dbRequest->cw_bio;

			$newDesc = explode( "\n", $dbRequest->cw_comment, 2 );
			$purposeCheck = explode( ':', $newDesc[0], 2 );

			if ( $purposeCheck[0] == 'Purpose' ) {
				$this->description = $newDesc[1];
				$this->purpose = $purposeCheck[1];
			} else {
				$this->description = $dbRequest->cw_comment;
			}

			$commentsReq = $this->dbw->newSelectQueryBuilder()
				->table( 'cw_comments' )
				->field( '*' )
				->where( [ 'cw_id' => $id ] )
				->orderBy( 'cw_timestamp', SelectQueryBuilder::SORT_DESC )
				->caller( __METHOD__ )
				->fetchResultSet();

			foreach ( $commentsReq as $comment ) {
				$userObj = $userFactory->newFromId( $comment->cw_comment_user );

				$this->comments[] = [
					'timestamp' => $comment->cw_comment_timestamp,
					'user' => $userObj,
					'comment' => $comment->cw_comment,
				];

				$this->involvedUsers[$comment->cw_comment_user] = $userObj;
			}
		} elseif ( $id ) {
			throw new RuntimeException( 'Unknown Request ID' );
		}
	}

	public function addComment(
		string $comment,
		UserIdentity $user,
		bool $log = true,
		string $type = 'comment',
		array $notifyUsers = []
	): bool {
		// don't post empty comments
		if ( !$comment || ctype_space( $comment ) ) {
			return false;
		}

		$this->dbw->insert(
			'cw_comments',
			[
				'cw_id' => $this->id,
				'cw_comment' => $comment,
				'cw_comment_timestamp' => $this->dbw->timestamp(),
				'cw_comment_user' => $user->getId(),
			],
			__METHOD__
		);

		// Don't notify the acting user of their action
		unset( $this->involvedUsers[$user->getId()] );

		if ( !$notifyUsers ) {
			$notifyUsers = $this->involvedUsers;
		}

		$this->sendNotification( $comment, $notifyUsers, $type );

		if ( $log ) {
			$this->log( $user, 'comment' );
		}

		return true;
	}

	private function sendNotification(
		string $comment,
		array $notifyUsers,
		string $type = 'comment'
	): void {
		// don't send notifications for empty comments
		if ( !$comment || ctype_space( $comment ) ) {
			return;
		}

		$reason = ( $type === 'declined' || $type === 'moredetails' ) ? 'reason' : 'comment';
		$notificationData = [
			'type' => "request-{$type}",
			'extra' => [
				'request-url' => SpecialPage::getTitleFor( 'RequestWikiQueue', (string)$this->id )->getFullURL(),
				$reason => $comment,
			],
		];

		MediaWikiServices::getInstance()->get( 'CreateWiki.NotificationsManager' )
			->sendNotification( $notificationData, $notifyUsers );
	}

	public function getComments(): array {
		return $this->comments;
	}

	public function getStatus(): string {
		return $this->status;
	}

	public function getVisibility(): int {
		return $this->visibility;
	}

	public function approve( UserIdentity $user, string $reason = null ): void {
		if ( $this->config->get( 'CreateWikiUseJobQueue' ) ) {
			$jobQueueGroup = MediaWikiServices::getInstance()->getJobQueueGroupFactory()->makeJobQueueGroup();
			$jobQueueGroup->push(
				new JobSpecification(
					CreateWikiJob::JOB_NAME,
					[
						'id' => $this->id,
						'dbname' => $this->dbname,
						'sitename' => $this->sitename,
						'language' => $this->language,
						'private' => (bool)$this->private,
						'category' => $this->category,
						'requester' => $this->requester->getName(),
						'creator' => $user->getName(),
					]
				)
			);

			$this->status = 'approved';
			$this->save();
			$this->addComment( 'Request approved. ' . ( $reason ?? '' ), $user, false );
			$this->log( $user, 'requestapprove' );

			if ( !is_int( $this->config->get( 'CreateWikiAIThreshold' ) ) ) {
				$this->tryAutoCreate();
			}
		} else {
			$wm = $this->wikiManagerFactory->newInstance( $this->dbname );
			// This runs checkDatabaseName and if it returns a
			// non-null value it is returning an error.
			$notCreated = $wm->create(
				$this->sitename,
				$this->language,
				(bool)$this->private,
				$this->category,
				$this->requester->getName(),
				$user->getName(),
				"[[Special:RequestWikiQueue/{$this->id}|Requested]]"
			);

			if ( $notCreated ) {
				$this->log( $user, 'create-failure' );
				throw new RuntimeException( $notCreated );
			} else {
				$this->status = 'approved';
				$this->save();

				$this->addComment( 'Request approved and wiki created. ' . ( $reason ?? '' ), $user, false );
			}
		}
	}

	public function decline( string $reason, UserIdentity $user ): void {
		$this->status = ( $this->status == 'approved' ) ? 'approved' : 'declined';
		$this->save();

		$this->addComment( $reason, $user, false, 'declined', [ $this->requester ] );

		$notifyUsers = $this->involvedUsers;

		unset(
			$notifyUsers[$this->requester->getId()],
			$notifyUsers[$user->getId()]
		);

		if ( $notifyUsers ) {
			$this->sendNotification( $reason, $notifyUsers );
		}

		$this->log( $user, 'requestdecline' );

		if ( !is_int( $this->config->get( 'CreateWikiAIThreshold' ) ) ) {
			$this->tryAutoCreate();
		}
	}

	public function onhold( string $reason, UserIdentity $user ): void {
		$this->status = ( $this->status == 'approved' ) ? 'approved' : 'onhold';
		$this->save();

		$this->addComment( $reason, $user, true, 'comment', [ $this->requester ] );

		$notifyUsers = $this->involvedUsers;

		unset(
			$notifyUsers[$this->requester->getId()],
			$notifyUsers[$user->getId()]
		);

		if ( $notifyUsers ) {
			$this->sendNotification( $reason, $notifyUsers );
		}

		$this->log( $user, 'requestonhold' );
	}

	public function moredetails( string $reason, UserIdentity $user ): void {
		$this->status = ( $this->status == 'approved' ) ? 'approved' : 'moredetails';
		$this->save();

		$this->addComment( $reason, $user, false, 'moredetails', [ $this->requester ] );

		$notifyUsers = $this->involvedUsers;

		unset(
			$notifyUsers[$this->requester->getId()],
			$notifyUsers[$user->getId()]
		);

		if ( $notifyUsers ) {
			$this->sendNotification( $reason, $notifyUsers );
		}

		$this->log( $user, 'requestmoredetails' );
	}

	public function log( UserIdentity $user, string $log ): void {
		$logEntry = new ManualLogEntry( 'farmer', $log );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( SpecialPage::getTitleFor( 'RequestWikiQueue', (string)$this->id ) );

		$logEntry->setParameters(
			[
				'4::id' => Message::rawParam(
					MediaWikiServices::getInstance()->getLinkRenderer()->makeKnownLink(
						Title::newFromText( SpecialPage::getTitleFor( 'RequestWikiQueue' ) . '/' . $this->id ),
						'#' . $this->id
					)
				),
			]
		);

		$logID = $logEntry->insert( $this->dbw );
		$logEntry->publish( $logID );
	}

	private function suppressionLog( UserIdentity $user, string $log ): void {
		$suppressionLogEntry = new ManualLogEntry( 'farmersuppression', $log );
		$suppressionLogEntry->setPerformer( $user );
		$suppressionLogEntry->setTarget( SpecialPage::getTitleFor( 'RequestWikiQueue', (string)$this->id ) );
		$suppressionLogEntry->setParameters(
			[
				'4::id' => Message::rawParam(
					MediaWikiServices::getInstance()->getLinkRenderer()->makeKnownLink(
						Title::newFromText( SpecialPage::getTitleFor( 'RequestWikiQueue' ) . '/' . $this->id ),
						'#' . $this->id
					)
				),
			]
		);
		$suppressionLogID = $suppressionLogEntry->insert();
		$suppressionLogEntry->publish( $suppressionLogID );
	}

	public function suppress(
		UserIdentity $user,
		int $level,
		bool $log = true
	): void {
		if ( $level === $this->visibility ) {
			// Nothing to do, the wiki request already has the requested suppression level
			return;
		}
		$this->visibility = $level;

		$this->save();

		if ( $log ) {
			switch ( $level ) {
				case 0:
					$this->suppressionLog( $user, 'public' );
					break;

				case 1:
					$this->suppressionLog( $user, 'delete' );
					break;

				case 2:
					$this->suppressionLog( $user, 'suppress' );
					break;
			}
		}
	}

	public function reopen( UserIdentity $user, bool $log = true ): void {
		$status = $this->status;

		$this->status = ( $status == 'approved' ) ? 'approved' : 'inreview';
		$this->save();

		if ( $log ) {
			$this->addComment( 'Updated request.', $user, true );
			if ( $status === 'declined' ) {
				$this->log( $user, 'requestreopen' );
			}
		}
	}

	public function save(): int {
		$comment = ( $this->config->get( 'CreateWikiPurposes' ) ) ? implode( "\n", [ 'Purpose: ' . $this->purpose, $this->description ] ) : $this->description;
		// Updating an existing request
		$this->dbw->update(
			'cw_requests',
			[
				'cw_comment' => $comment,
				'cw_dbname' => $this->dbname,
				'cw_language' => $this->language,
				'cw_private' => $this->private,
				'cw_status' => $this->status,
				'cw_sitename' => $this->sitename,
				'cw_timestamp' => $this->timestamp,
				'cw_url' => $this->url,
				'cw_user' => $this->requester->getId(),
				'cw_category' => $this->category,
				'cw_visibility' => $this->visibility,
				'cw_bio' => $this->bio,
			],
			[
				'cw_id' => $this->id,
			],
			__METHOD__
		);

		return $this->dbw->insertId();
	}

	public function tryAutoCreate(): void {
		$jobQueueGroup = MediaWikiServices::getInstance()->getJobQueueGroupFactory()->makeJobQueueGroup();
		$jobQueueGroup->push(
			new JobSpecification(
				RequestWikiAIJob::JOB_NAME,
				[
					'description' => $this->description,
					'id' => $this->id,
				]
			)
		);
	}
}
