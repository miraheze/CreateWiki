<?php

namespace Miraheze\CreateWiki\CreateWiki;

use Exception;
use Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Miraheze\CreateWiki\RequestWiki\WikiRequest;
use Miraheze\CreateWiki\WikiManager;

class CreateWikiJob extends Job {
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'CreateWikiJob', $params );
	}

	public function run() {
		$hookRunner = MediaWikiServices::getInstance()->get( 'CreateWikiHookRunner' );
		$wm = new WikiManager( $this->params['dbname'], $hookRunner );
		$wr = new WikiRequest( $this->params['id'], $hookRunner );

		$notValid = $wm->checkDatabaseName( $this->params['dbname'] );

		if ( $notValid ) {
			$wr->addComment( $notValid, User::newSystemUser( 'CreateWiki Extension' ), false );

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
		} catch ( Exception $e ) {
			$wr->addComment( 'Exception experienced creating the wiki. Error is: ' . $e->getMessage(), User::newSystemUser( 'CreateWiki Extension' ), true );
			$wr->reopen( User::newSystemUser( 'CreateWiki Extension' ), false );
			$wr->log( User::newSystemUser( 'CreateWiki Extension' ), 'create-failure' );

			return true;
		}

		$wr->addComment( 'Wiki created.', User::newSystemUser( 'CreateWiki Extension' ), false );

		return true;
	}
}
