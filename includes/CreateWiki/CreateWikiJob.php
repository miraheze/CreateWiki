<?php

namespace Miraheze\CreateWiki\CreateWiki;

use Exception;
use Job;
use MediaWiki\User\User;
use Miraheze\CreateWiki\RequestWiki\WikiRequest;
use Miraheze\CreateWiki\Services\WikiManagerFactory;

class CreateWikiJob extends Job {

	public const JOB_NAME = 'CreateWikiJob';

	private WikiManagerFactory $wikiManagerFactory;

	private int $id;
	private bool $private;

	private string $category;
	private string $creator;
	private string $dbname;
	private string $language;
	private string $requester;
	private string $sitename;

	/**
	 * @param array $params
	 * @param WikiManagerFactory $wikiManagerFactory
	 */
	public function __construct(
		array $params,
		WikiManagerFactory $wikiManagerFactory
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->category = $params['category'];
		$this->creator = $params['creator'];
		$this->dbname = $params['dbname'];
		$this->id = $params['id'];
		$this->language = $params['language'];
		$this->private = $params['private'];
		$this->requester = $params['requester'];
		$this->sitename = $params['sitename'];

		$this->wikiManagerFactory = $wikiManagerFactory;
	}

	/**
	 * @return bool
	 */
	public function run(): bool {
		$wm = $this->wikiManagerFactory->newInstance( $this->dbname );
		$wr = new WikiRequest( $this->id );

		try {
			// This runs checkDatabaseName and if it returns a
			// non-null value it is returning an error.
			$notCreated = $wm->create(
				sitename: $this->sitename,
				language: $this->language,
				private: $this->private,
				category: $this->category,
				requester: $this->requester,
				actor: $this->creator,
				reason: "[[Special:RequestWikiQueue/{$this->id}|Requested]]"
			);

			if ( $notCreated ) {
				$wr->addComment( $notCreated, User::newSystemUser( 'CreateWiki Extension' ), false );
				$wr->log( User::newSystemUser( 'CreateWiki Extension' ), 'create-failure' );
				return true;
			}
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
