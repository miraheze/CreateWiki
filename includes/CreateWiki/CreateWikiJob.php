<?php

class CreateWikiJob extends Job {
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'CreateWikiJob', $params );
	}

	public function run() {
		$wm = new WikiManager( $this->params['dbname'] );
		$wr = new WikiRequest( $this->params['id'] );

		$notValid = $wm->checkDatabaseName( $this->params['dbname'] );

		if ( $notValid ) {
			$wr->addComment( $notValid, User::newSystemUser( 'CreateWiki Extension' ) );
			return true;
		}

		try {
			$wm->create(
				$this->params['sitename'],
				$this->params['language'],
				$this->params['private'],
				$this->params['category'],
				$this->params['requester'],
				$this->params['creator'],
				"[[Special:RequestWikiQueue/{$this->params['id']}|Requested]]"
			);
		} catch ( MWException $e ) {
			$wr->addComment( 'Exception experienced creating the wiki.', User::newSystemUser( 'CreateWiki Extension' ) );
			return true;
		}

		$wr->addComment( 'Wiki created.', User::newSystemUser( 'CreateWiki Extension' ) );
		return true;
	}
}
