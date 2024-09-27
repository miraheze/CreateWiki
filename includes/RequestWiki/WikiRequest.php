<?php

namespace Miraheze\CreateWiki\RequestWiki;

use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use Miraheze\CreateWiki\CreateWiki\CreateWikiJob;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\WikiManager;
use RuntimeException;
use StatusValue;
use UnexpectedValueException;
use Wikimedia\Rdbms\IDatabase;

class WikiRequest {

	private Config $config;
	private CreateWikiHookRunner $hookRunner;
	private IDatabase $dbw;

	public User $requester;

	public string $dbname;
	public string $description;
	public string $language;
	public ?int $private;
	public string $sitename;
	public string $url;
	public string $category;
	public int $timestamp;
	public int $bio;
	public ?string $purpose = null;

	private int $id;
	private string $status = 'inreview';
	private array $comments = [];
	private array $involvedUsers = [];
	private int $visibility = 0;

	public function __construct( int $id = null, CreateWikiHookRunner $hookRunner = null ) {
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );
		$this->hookRunner = $hookRunner ?? MediaWikiServices::getInstance()->get( 'CreateWikiHookRunner' );

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$this->dbw = $lbFactory->getMainLB( $this->config->get( 'CreateWikiGlobalWiki' ) )
			->getMaintenanceConnectionRef( DB_PRIMARY, [], $this->config->get( 'CreateWikiGlobalWiki' ) );

		$userFactory = MediaWikiServices::getInstance()->getUserFactory();

		$dbRequest = $this->dbw->selectRow(
			'cw_requests',
			'*',
			[
				'cw_id' => $id,
			],
			__METHOD__
		);

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

			$commentsReq = $this->dbw->select(
				'cw_comments',
				'*',
				[
					'cw_id' => $id,
				],
				__METHOD__,
				[
					'cw_timestamp DESC',
				]
			);

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

	public function addComment( string $comment, UserIdentity $user, bool $log = true, string $type = 'comment', array $notifyUsers = [] ): bool {
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

	private function sendNotification( string $comment, array $notifyUsers, string $type = 'comment' ) {
		// don't send notifications for empty comments
		if ( !$comment || ctype_space( $comment ) ) {
			return;
		}

		$reason = ( $type === 'declined' || $type === 'moredetails' ) ? 'reason' : 'comment';
		$notificationData = [
			'type' => "request-{$type}",
			'extra' => [
				'request-url' => SpecialPage::getTitleFor( 'RequestWikiQueue', $this->id )->getFullURL(),
				$reason => $comment,
			],
		];

		MediaWikiServices::getInstance()->get( 'CreateWiki.NotificationsManager' )
			->sendNotification( $notificationData, $notifyUsers );
	}

	public function getComments() {
		return $this->comments;
	}

	public function getStatus() {
		return $this->status;
	}

	public function getVisibility(): int {
		return $this->visibility;
	}

	public function approve( UserIdentity $user, string $reason = null ) {
		if ( $this->config->get( 'CreateWikiUseJobQueue' ) ) {
			$jobParams = [
				'id' => $this->id,
				'dbname' => $this->dbname,
				'sitename' => $this->sitename,
				'language' => $this->language,
				'private' => $this->private,
				'category' => $this->category,
				'requester' => $this->requester->getName(),
				'creator' => $user->getName(),
			];

			$jobQueueGroup = MediaWikiServices::getInstance()->getJobQueueGroupFactory()->makeJobQueueGroup();
			$jobQueueGroup->push( new CreateWikiJob( Title::newMainPage(), $jobParams ) );

			$this->status = 'approved';
			$this->save();
			$this->addComment( 'Request approved. ' . ( $reason ?? '' ), $user, false );
			$this->log( $user, 'requestapprove' );

			if ( !is_int( $this->config->get( 'CreateWikiAIThreshold' ) ) ) {
				$this->tryAutoCreate();
			}
		} else {
			$wm = new WikiManager( $this->dbname, $this->hookRunner );

			$validName = $wm->checkDatabaseName( $this->dbname );

			$notCreated = $wm->create( $this->sitename, $this->language, $this->private, $this->category, $this->requester->getName(), $user->getName(), "[[Special:RequestWikiQueue/{$this->id}|Requested]]" );

			if ( $validName || $notCreated ) {
				$this->log( $user, 'create-failure' );

				throw new RuntimeException( $notCreated ?? $validName );
			} else {
				$this->status = 'approved';
				$this->save();

				$this->addComment( 'Request approved and wiki created. ' . ( $reason ?? '' ), $user, false );
			}
		}
	}

	public function decline( string $reason, UserIdentity $user ) {
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

	public function onhold( string $reason, UserIdentity $user ) {
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

	public function moredetails( string $reason, UserIdentity $user ) {
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

	public function log( UserIdentity $user, string $log ) {
		$logEntry = new ManualLogEntry( 'farmer', $log );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( SpecialPage::getTitleFor( 'RequestWikiQueue', $this->id ) );

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

	private function suppressionLog( UserIdentity $user, string $log ) {
		$suppressionLogEntry = new ManualLogEntry( 'farmersuppression', $log );
		$suppressionLogEntry->setPerformer( $user );
		$suppressionLogEntry->setTarget( SpecialPage::getTitleFor( 'RequestWikiQueue', $this->id ) );
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

	public function suppress( UserIdentity $user, int $level, $log = true ) {
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

	public function reopen( UserIdentity $user, $log = true ) {
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

	public function save() {
		$inReview = $this->dbw->select(
			'cw_requests',
			[
				'cw_comment',
				'cw_dbname',
				'cw_sitename',
			],
			[
				'cw_status' => 'inreview',
			],
			__METHOD__
		);

		foreach ( $inReview as $row ) {
			if (
				$this->id === null
				&& ( $this->sitename == $row->cw_sitename
				|| $this->dbname == $row->cw_dbname
				|| $this->description == $row->cw_comment )
			) {
				throw new UnexpectedValueException( 'Request too similar to an existing open request!' );
			}
		}

		$comment = ( $this->config->get( 'CreateWikiPurposes' ) ) ? implode( "\n", [ 'Purpose: ' . $this->purpose, $this->description ] ) : $this->description;

		$rows = [
			'cw_comment' => $comment,
			'cw_dbname' => $this->dbname,
			'cw_language' => $this->language,
			'cw_private' => $this->private,
			'cw_status' => $this->status,
			'cw_sitename' => $this->sitename,
			'cw_timestamp' => $this->timestamp ?? $this->dbw->timestamp(),
			'cw_url' => $this->url,
			'cw_user' => $this->requester->getId(),
			'cw_category' => $this->category,
			'cw_visibility' => $this->visibility,
			'cw_bio' => $this->bio,
		];

		if ( !$this->id ) {
			// New wiki request
			$this->dbw->insert(
				'cw_requests',
				[
					$rows,
				],
				__METHOD__
			);
		} else {
			// Updating an existing request
			$this->dbw->update(
				'cw_requests',
				$rows,
				[
					'cw_id' => $this->id,
				],
				__METHOD__
			);
		}

		if ( is_int( $this->config->get( 'CreateWikiAIThreshold' ) ) ) {
			$this->tryAutoCreate();
		}

		return $this->dbw->insertId();
	}

	public function tryAutoCreate() {
		$jobQueueGroup = MediaWikiServices::getInstance()->getJobQueueGroupFactory()->makeJobQueueGroup();
		$jobQueueGroup->push( new RequestWikiAIJob(
			Title::newMainPage(),
			[
				'description' => $this->description,
				'id' => $this->id,
			]
		) );
	}

	/**
	 * Extract database name from subdomain and automatically configure url and dbname
	 *
	 * @param string $subdomain subdomain
	 *
	 * @return StatusValue|true
	 */
	public function parseSubdomain( string $subdomain ) {
		$subdomain = strtolower( $subdomain );
		if ( strpos( $subdomain, $this->config->get( 'CreateWikiSubdomain' ) ) !== false ) {
			$subdomain = str_replace( '.' . $this->config->get( 'CreateWikiSubdomain' ), '', $subdomain );
		}

		$disallowedSubdomains = CreateWikiRegexConstraint::regexFromArrayOrString(
			$this->config->get( 'CreateWikiDisallowedSubdomains' ), '/^(', ')+$/',
			'CreateWikiDisallowedSubdomains'
		);

		// Make the subdomain a dbname
		$database = $subdomain . $this->config->get( 'CreateWikiDatabaseSuffix' );
		if ( in_array( $database, $this->config->get( 'LocalDatabases' ) ) ) {
			return StatusValue::newFatal( 'createwiki-error-subdomaintaken' );

		} elseif ( !ctype_alnum( $subdomain ) ) {
			return StatusValue::newFatal( 'createwiki-error-notalnum' );

		} elseif ( preg_match( $disallowedSubdomains, $subdomain ) ) {
			return StatusValue::newFatal( 'createwiki-error-disallowed' );

		} else {
			$this->dbname = $subdomain . $this->config->get( 'CreateWikiDatabaseSuffix' );
			$this->url = $subdomain . '.' . $this->config->get( 'CreateWikiSubdomain' );

			return true;
		}
	}
}
