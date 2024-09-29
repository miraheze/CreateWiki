<?php

namespace Miraheze\CreateWiki\Services;

use JobSpecification;
use ManualLogEntry;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Miraheze\CreateWiki\Jobs\CreateWikiJob;
use Miraheze\CreateWiki\Jobs\RequestWikiAIJob;
use RuntimeException;
use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

class WikiRequestManager {

	public const CONSTRUCTOR_OPTIONS = [
		'CreateWikiAIThreshold',
		'CreateWikiGlobalWiki',
		'CreateWikiUseJobQueue',
	];

	private ServiceOptions $options;
	private IDatabase $dbw;

	private WikiManagerFactory $wikiManagerFactory;

	private stdClass|bool $row;
	private LinkRenderer $linkRenderer;
	private UserFactory $userFactory;
	private CreateWikiNotificationsManager $notificationsManager;
	private IConnectionProvider $connectionProvider;
	private JobQueueGroupFactory $jobQueueGroupFactory;

	private int $ID;

	public function __construct(
		IConnectionProvider $connectionProvider,
		CreateWikiNotificationsManager $notificationsManager,
		JobQueueGroupFactory $jobQueueGroupFactory,
		LinkRenderer $linkRenderer,
		UserFactory $userFactory,
		WikiManagerFactory $wikiManagerFactory,
		ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->connectionProvider = $connectionProvider;
		$this->notificationsManager = $notificationsManager;
		$this->jobQueueGroupFactory = $jobQueueGroupFactory;
		$this->linkRenderer = $linkRenderer;
		$this->userFactory = $userFactory;
		$this->wikiManagerFactory = $wikiManagerFactory;
		$this->options = $options;
	}

	public function fromID( int $requestID ): void {
		$this->dbw = $this->connectionProvider->getPrimaryDatabase(
			$this->options->get( 'CreateWikiGlobalWiki' )
		);

		$this->ID = $requestID;

		$this->row = $this->dbw->newSelectQueryBuilder()
			->table( 'cw_requests' )
			->field( '*' )
			->where( [ 'cw_id' => $requestID ] )
			->caller( __METHOD__ )
			->fetchRow();
	}

	public function exists(): bool {
		return (bool)$this->row;
	}

	public function addComment(
		string $comment,
		User $user,
		bool $log,
		string $type
	) {
		$this->dbw->newInsertQueryBuilder()
			->insertInto( 'cw_comments' )
			->row( [
				'cw_id' => $this->ID,
				'cw_comment' => $comment,
				'cw_comment_timestamp' => $this->dbw->timestamp(),
				'cw_comment_user' => $user->getId(),
			] )
			->caller( __METHOD__ )
			->execute();

		$this->sendNotification( $comment, $type, $user );

		if ( $log ) {
			$this->log( $user, 'comment' );
		}
	}

	private function sendNotification(
		string $comment,
		string $type,
		User $user
	): void {
		$requestLink = SpecialPage::getTitleFor( 'RequestWikiQueue', (string)$this->ID )->getFullURL();

		$involvedUsers = array_values( array_filter(
			array_diff( $this->getInvolvedUsers(), [ $user ] )
		) );

		$notificationData = [
			'type' => "request-{$type}",
			'extra' => [
				'request-url' => $requestLink,
				'comment' => $comment,
			],
		];

		$this->notificationsManager->sendNotification( $notificationData, $notifyUsers );
	}

	public function getComments(): array {
		$res = $this->dbw->newSelectQueryBuilder()
			->table( 'cw_comments' )
			->field( '*' )
			->where( [ 'cw_id' => $this->ID ] )
			->orderBy( 'cw_comment_timestamp', SelectQueryBuilder::SORT_DESC )
			->caller( __METHOD__ )
			->fetchResultSet();

		if ( !$res->numRows() ) {
			return [];
		}

		$comments = [];
		foreach ( $res as $row ) {
			$user = $this->userFactory->newFromId( $row->cw_user );

			$comments[] = [
				'comment' => $row->cw_comment,
				'timestamp' => $row->cw_comment_timestamp,
				'user' => $user,
			];
		}

		return $comments;
	}

	public function getInvolvedUsers(): array {
		return array_unique( array_merge( array_column( $this->getComments(), 'user' ), [ $this->getRequester() ] ) );
	}

	public function getVisibility(): int {
		return $this->row->cw_visibility;
	}

	public function getRequester(): User {
		return $this->userFactory->newFromId( $this->row->cw_user );
	}

	public function getStatus(): string {
		return $this->row->cw_status;
	}

	public function getSitename(): string {
		return $this->row->cw_sitename;
	}

	public function getLanguage(): string {
		return $this->row->cw_language;
	}

	public function getTimestamp(): string {
		return $this->row->cw_timestamp;
	}

	public function getUrl(): string {
		return $this->row->cw_url;
	}

	public function getDescription(): string {
		$comment = explode( "\n", $this->row->cw_comment, 2 );
		$purposeCheck = explode( ':', $comment[0], 2 );

		if ( $purposeCheck[0] === 'Purpose' ) {
			return $comment[1];
		}

		return $this->row->cw_comment;
	}

	public function getPurpose(): ?string {
		$comment = explode( "\n", $this->row->cw_comment, 2 );
		$purposeCheck = explode( ':', $comment[0], 2 );

		if ( $purposeCheck[0] === 'Purpose' ) {
			return $purposeCheck[1];
		}
 
		return null;
	}

	public function approve( UserIdentity $user, string $reason = null ): void {
		if ( $this->options->get( 'CreateWikiUseJobQueue' ) ) {
			$jobQueueGroup = $this->jobQueueGroupFactory->makeJobQueueGroup();
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

			if ( !is_int( $this->options->get( 'CreateWikiAIThreshold' ) ) ) {
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

		if ( !is_int( $this->options->get( 'CreateWikiAIThreshold' ) ) ) {
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
		$logEntry->setTarget( SpecialPage::getTitleFor( 'RequestWikiQueue', (string)$this->ID ) );

		$logEntry->setParameters(
			[
				'4::id' => Message::rawParam(
					$this->linkRenderer->makeKnownLink(
						SpecialPage::getTitleValueFor( 'RequestWikiQueue', (string)$this->ID ),
						'#' . $this->ID
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
		$suppressionLogEntry->setTarget( SpecialPage::getTitleFor( 'RequestWikiQueue', (string)$this->ID ) );
		$suppressionLogEntry->setParameters(
			[
				'4::id' => Message::rawParam(
					$this->linkRenderer->makeKnownLink(
						SpecialPage::getTitleValueFor( 'RequestWikiQueue', $this->ID ),
						'#' . $this->ID
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

	public function tryAutoCreate(): void {
		$jobQueueGroup = $this->jobQueueGroupFactory->makeJobQueueGroup();
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
