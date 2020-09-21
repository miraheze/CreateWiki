<?php

use MediaWiki\MediaWikiServices;

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

		$this->sendNotification( 'comment', $comment );
	}

	public function getComments() {
		return $this->comments;
	}

	public function getStatus() {
		return $this->status;
	}

	public function approve( User $user, string $reason = null ) {
		$wm = new WikiManager( $this->dbname );
		$validName = $wm->checkDatabaseName( $this->dbname );

		$notCreated = $wm->create( $this->sitename, $this->language, $this->private, $this->category, $this->requester->getName(), $user->getName(), "[[Special:RequestWikiQueue/{$this->id}|Requested]]" );

		if ( $validName || $notCreated ) {
			throw new MWException( $notCreated ?? $validName );
		} else {
			$this->status = 'approved';
			$this->save();
			$this->addComment( 'Request approved and wiki created. ' . ( $reason ?? '' ) , $user );
		}
	}

	public function decline( string $reason, User $user ) {
		$this->status = 'declined';
		$this->save();
		$this->addComment( $reason, $user );
		$this->sendNotification( 'declined', $reason );
	}

	public function reopen( User $user ) {
		$this->status = 'inreview';
		$this->save();
		$this->addComment( 'Updated request.', $user );
	}

	private function sendNotification( string $type, string $text ) {
		 if ( !$this->config->get( 'CreateWikiUseEchoNotifications' ) ) {
		 	return;
		 }

		if ( $type == 'declined' ) {
			$echoType = 'request-declined';
			$echoExtra = [
				'request-url' => SpecialPage::getTitleFor( 'RequestWikiQueue', $this->id )->getFullURL(),
				'reason' => $text,
				'notifyAgent' => true
			];
		} else {
			$echoType = 'request-comment';
			$echoExtra = [
				'request-url' => SpecialPage::getTitleFor( 'RequestWikiQueue', $this->id )->getFullURL(),
				'comment' => $text,
				'notifyAgent' => true
			];
		}

		foreach ( $this->involvedUsers as $user => $object ) {
			EchoEvent::create(
				[
					'type' => $echoType,
					'extra' => $echoExtra,
					'agent' => $object
				]
			);
		}
	}

	public function save() {
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

}
