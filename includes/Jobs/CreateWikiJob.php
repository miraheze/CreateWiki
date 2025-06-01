<?php

namespace Miraheze\CreateWiki\Jobs;

use Exception;
use Job;
use MediaWiki\User\User;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use MWExceptionHandler;

class CreateWikiJob extends Job {

	public const JOB_NAME = 'CreateWikiJob';

	private readonly int $id;
	private readonly bool $private;

	private readonly string $category;
	private readonly string $creator;
	private readonly string $dbname;
	private readonly string $language;
	private readonly string $requester;
	private readonly string $sitename;

	private readonly array $extra;

	public function __construct(
		array $params,
		private readonly WikiManagerFactory $wikiManagerFactory,
		private readonly WikiRequestManager $wikiRequestManager
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->category = $params['category'];
		$this->creator = $params['creator'];
		$this->dbname = $params['dbname'];
		$this->extra = $params['extra'];
		$this->id = $params['id'];
		$this->language = $params['language'];
		$this->private = $params['private'];
		$this->requester = $params['requester'];
		$this->sitename = $params['sitename'];
	}

	/** @inheritDoc */
	public function run(): bool {
		$this->wikiRequestManager->loadFromID( $this->id );
		$wikiManager = $this->wikiManagerFactory->newInstance( $this->dbname );

		try {
			// This runs validateDatabaseName and if it returns a
			// non-null value it is returning an error.
			$notCreated = $wikiManager->create(
				sitename: $this->sitename,
				language: $this->language,
				private: $this->private,
				category: $this->category,
				requester: $this->requester,
				actor: $this->creator,
				extra: $this->extra,
				reason: "[[Special:RequestWikiQueue/{$this->id}|Requested]]"
			);

			if ( $notCreated ) {
				$this->wikiRequestManager->addComment(
					comment: $notCreated,
					user: User::newSystemUser( 'CreateWiki Extension' ),
					log: false,
					type: 'comment',
					// Use all involved users
					notifyUsers: []
				);

				$this->wikiRequestManager->log(
					user: User::newSystemUser( 'CreateWiki Extension' ),
					action: 'create-failure'
				);

				return true;
			}
		} catch ( Exception $e ) {
			$this->wikiRequestManager->addComment(
				comment: 'Exception experienced creating the wiki. Error is: ' . $e->getMessage(),
				user: User::newSystemUser( 'CreateWiki Extension' ),
				log: false,
				type: 'comment',
				// Use all involved users
				notifyUsers: []
			);

			$this->wikiRequestManager->log(
				user: User::newSystemUser( 'CreateWiki Extension' ),
				action: 'create-failure'
			);

			MWExceptionHandler::logException( $e );

			return true;
		}

		$this->wikiRequestManager->addComment(
			comment: 'Wiki created.',
			user: User::newSystemUser( 'CreateWiki Extension' ),
			log: false,
			type: 'comment',
			// Use all involved users
			notifyUsers: []
		);

		return true;
	}
}
