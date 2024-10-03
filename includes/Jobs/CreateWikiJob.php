<?php

namespace Miraheze\CreateWiki\Jobs;

use Exception;
use Job;
use MediaWiki\User\User;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Miraheze\CreateWiki\Services\WikiRequestManager;

class CreateWikiJob extends Job {

	public const JOB_NAME = 'CreateWikiJob';

	private WikiManagerFactory $wikiManagerFactory;
	private WikiRequestManager $wikiRequestManager;

	private int $id;
	private bool $private;

	private string $category;
	private string $creator;
	private string $dbname;
	private string $language;
	private string $requester;
	private string $sitename;

	private array $extra;

	public function __construct(
		array $params,
		WikiManagerFactory $wikiManagerFactory,
		WikiRequestManager $wikiRequestManager
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

		$this->wikiManagerFactory = $wikiManagerFactory;
		$this->wikiRequestManager = $wikiRequestManager;
	}

	/**
	 * @return bool
	 */
	public function run(): bool {
		$this->wikiRequestManager->fromID( $this->id );
		$wikiManager = $this->wikiManagerFactory->newInstance( $this->dbname );

		try {
			// This runs checkDatabaseName and if it returns a
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
					type: 'comment'
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
				log: true,
				type: 'comment'
			);

			$this->wikiRequestManager->log(
				user: User::newSystemUser( 'CreateWiki Extension' ),
				action: 'create-failure'
			);

			return true;
		}

		$this->wikiRequestManager->addComment(
			comment: 'Wiki created.',
			user: User::newSystemUser( 'CreateWiki Extension' ),
			log: false,
			type: 'comment'
		);

		return true;
	}
}
