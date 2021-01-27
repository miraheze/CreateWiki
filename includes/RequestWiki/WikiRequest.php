<?php

use MediaWiki\MediaWikiServices;
use Phpml\ModelManager;

class WikiRequest {
	public $dbname;
	public $description;
	public $language;
	public $private;
	public $sitename;
	public $url;
	public $category;
	public $requester;
	public $visibility = 0;
	public $timestamp;

	private $id;
	private $dbw;
	private $config;
	private $status = 'inreview';
	private $comments = [];
	private $involvedUsers = [];

	public function __construct( int $id = null ) {
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$this->dbw = wfGetDB( DB_MASTER, [], $this->config->get( 'CreateWikiGlobalWiki' ) );

		$dbRequest = $this->dbw->selectRow(
			'cw_requests',
			'*',
			[
				'cw_id' => $id
			]
		);

		if ( $dbRequest ) {
			$this->id = $dbRequest->cw_id;
			$this->dbname = $dbRequest->cw_dbname;
			$this->description = $dbRequest->cw_comment;
			$this->language = $dbRequest->cw_language;
			$this->private = $dbRequest->cw_private;
			$this->sitename = $dbRequest->cw_sitename;
			$this->url = $dbRequest->cw_url;
			$this->category = $dbRequest->cw_category;
			$this->requester = User::newFromId( $dbRequest->cw_user );
			$this->status = $dbRequest->cw_status;
			$this->timestamp = $dbRequest->cw_timestamp;
			$this->visibility = $dbRequest->cw_visibility;

			$commentsReq = $this->dbw->select(
				'cw_comments',
				'*',
				[
					'cw_id' => $id
				],
				__METHOD__,
				[
					'cw_timestamp DESC'
				]
			);

			foreach ( $commentsReq as $comment ) {
				$userObj = User::newFromId( $comment->cw_comment_user );

				$this->comments[] = [
					'timestamp' => $comment->cw_comment_timestamp,
					'user' => $userObj,
					'comment' => $comment->cw_comment
				];

				$this->involvedUsers[$comment->cw_comment_user] = $userObj;
			}
		} elseif ( $id ) {
			throw new MWException( 'Unknown Request ID' );
		}
	}

	public function addComment( string $comment, User $user ) {
		$this->dbw->insert(
			'cw_comments',
			[
				'cw_id' => $this->id,
				'cw_comment' => $comment,
				'cw_comment_timestamp' => $this->dbw->timestamp(),
				'cw_comment_user' => $user->getId()
			]
		);

		$this->sendNotification( $comment, $user );
	}

	public function getComments() {
		return $this->comments;
	}

	public function getStatus() {
		return $this->status;
	}

	public function approve( User $user, string $reason = null ) {
		if ( $this->config->get( 'CreateWikiUseJobQueue') ) {
			$jobParams = [
				'id' => $this->id,
				'dbname' => $this->dbname,
				'sitename' => $this->sitename,
				'language' => $this->language,
				'private' => $this->private,
				'category' => $this->category,
				'requester' => $this->requester->getName(),
				'creator' => $user->getName()
			];
			JobQueueGroup::singleton()->push( new CreateWikiJob( Title::newMainPage(), $jobParams ) );
			$this->status = 'approved';
			$this->save();
			$this->addComment( 'Request approved. ' . ( $reason ?? '' ), $user );
			$this->log( $user, 'requestaccept' );
			$this->tryAutoCreate();
		} else {
			$wm = new WikiManager( $this->dbname );
			$validName = $wm->checkDatabaseName( $this->dbname );

			$notCreated = $wm->create( $this->sitename, $this->language, $this->private, $this->category, $this->requester->getName(), $user->getName(), "[[Special:RequestWikiQueue/{$this->id}|Requested]]" );

			if ( $validName || $notCreated ) {
				throw new MWException( $notCreated ?? $validName );
			} else {
				$this->status = 'approved';
				$this->save();
				$this->addComment( 'Request approved and wiki created. ' . ( $reason ?? '' ), $user );
			}
		}
	}

	public function decline( string $reason, User $user ) {
		$this->status = ( $this->status == 'approved' ) ? 'approved' : 'declined';
		$this->save();
		$this->addComment( $reason, $user );
		$this->log( $user, 'requestdecline' );
		$this->tryAutoCreate();
	}

	private function log( User $user, string $log ) {
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
				)
			]
		);
		$logID = $logEntry->insert( $this->dbw );
		$logEntry->publish( $logID );
	}

	public function reopen( User $user, $log = true ) {
		$this->status = ( $this->status == 'approved' ) ? 'approved' : 'inreview';
		$this->save();
		if ( $log ) {
			$this->addComment( 'Updated request.', $user );
		}
	}

	private function sendNotification( string $text, User $user ) {
		 if ( !$this->config->get( 'CreateWikiUseEchoNotifications' ) ) {
		 	return;
		 }

		// Don't notify the acting user of their action
		unset( $this->involvedUsers[$user->getId()] );

		foreach ( $this->involvedUsers as $user => $object ) {
			EchoEvent::create(
				[
					'type' => 'request-comment',
					'extra' => $echoExtra = [
						'request-url' => SpecialPage::getTitleFor( 'RequestWikiQueue', $this->id )->getFullURL(),
						'comment' => $text,
						'notifyAgent' => true
					],
					'agent' => $object
				]
			);
		}
	}

	public function save() {
		$inReview = $this->dbw->select(
			'cw_requests',
			[
				'cw_comment',
				'cw_dbname',
				'cw_sitename'
			],
			[
				'cw_status' => 'inreview'
			]
		);

		foreach ( $inReview as $row ) {
			if (
				is_null( $this->id )
				&& ( $this->sitename == $row->cw_sitename
				|| $this->dbname == $row->cw_dbname
				|| $this->description == $row->cw_comment )
			) {
				throw new MWException( 'Request too similar to an existing open request!' );
			}
		}

		$urlExp = explode( '.', $this->url, 2 );
		if ( $urlExp[1] == $this->config->get( 'CreateWikiSubdomain' ) ) {
			$this->dbname = $urlExp[0] . 'wiki';
		}

		$rows = [
			'cw_comment' => $this->description,
			'cw_dbname' => $this->dbname,
			'cw_language' => $this->language,
			'cw_private' => $this->private,
			'cw_status' => $this->status,
			'cw_sitename' => $this->sitename,
			'cw_timestamp' => $this->timestamp ?? $this->dbw->timestamp(),
			'cw_url' => $this->url,
			'cw_user' => $this->requester->getId(),
			'cw_category' => $this->category,
			'cw_visibility' => $this->visibility
		];

		$this->dbw->upsert(
			'cw_requests',
			[
				'cw_id' => $this->id
			] + $rows,
			'cw_id',
			$rows
		);

		return $this->dbw->insertId();
	}
	
	public function tryAutoCreate( bool $job = true ) {
		// Memory exceeds in a web request but not via command line, work around for now is a job via web
		if ( $job ) {
			JobQueueGroup::singleton()->push( new RequestWikiAIJob( Title::newMainPage(), [ 'id' => $this->id ] ) );
		} else {
			$modelFile = $this->config->get( 'CreateWikiPersistentModelFile' );

			if ( file_exists( $modelFile ) ) {
				$modelManager = new ModelManager();
				$pipeline = $modelManager->restoreFromFile( $modelFile );
				$tokenDescription = (array)strtolower( $this->description );
				$pipeline->transform( $tokenDescription );
				$approveScore = $pipeline->getEstimator()->predictProbability( $tokenDescription )[0]['approved'];

				$this->addComment( 'Experimental Approval Score: ' . (string)round( $approveScore, 2 ), User::newSystemUser( 'CreateWiki Extension' ) );
			}
		}
	}

}
