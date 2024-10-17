<?php

namespace Miraheze\CreateWiki\Jobs;

use Job;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\User\User;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Phpml\ModelManager;

class RequestWikiAIJob extends Job {

	public const JOB_NAME = 'RequestWikiAIJob';

	private Config $config;
	private CreateWikiHookRunner $hookRunner;
	private WikiRequestManager $wikiRequestManager;

	private int $id;
	private string $reason;

	public function __construct(
		array $params,
		ConfigFactory $configFactory,
		CreateWikiHookRunner $hookRunner,
		WikiRequestManager $wikiRequestManager
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->config = $configFactory->makeConfig( 'CreateWiki' );
		$this->hookRunner = $hookRunner;
		$this->wikiRequestManager = $wikiRequestManager;

		$this->id = $params['id'];
		$this->reason = $params['reason'];
	}

	public function run(): bool {
		$this->wikiRequestManager->loadFromID( $this->id );
		$modelFile = $this->config->get( ConfigNames::PersistentModelFile );

		$pipeline = '';
		$this->hookRunner->onCreateWikiReadPersistentModel( $pipeline );

		if ( $pipeline || ( $modelFile && file_exists( $modelFile ) ) ) {
			if ( !$pipeline ) {
				$modelManager = new ModelManager();
				$pipeline = $modelManager->restoreFromFile( $modelFile );
			}

			$token = (array)strtolower( $this->reason );

			// @phan-suppress-next-line PhanUndeclaredMethod
			$pipeline->transform( $token );

			// @phan-suppress-next-line PhanUndeclaredMethod
			$approveScore = $pipeline->getEstimator()->predictProbability( $token )[0]['approved'];

			$this->wikiRequestManager->addComment(
				comment: "'''Approval Score''': " . (string)round( $approveScore, 2 ),
				user: User::newSystemUser( 'CreateWiki Extension' ),
				log: false,
				type: 'comment',
				// Use all involved users
				notifyUsers: []
			);

			if (
				$this->config->get( ConfigNames::AIThreshold ) > 0 &&
				round( $approveScore ) >= $this->config->get( ConfigNames::AIThreshold ) &&
				$this->canAutoApprove()
			) {
				// Start query builder so that it can set the status
				$this->wikiRequestManager->startQueryBuilder();

				$this->wikiRequestManager->approve(
					user: User::newSystemUser( 'CreateWiki Extension' ),
					// Only post the default 'Request approved.' comment
					comment: ''
				);

				// Execute query builder to commit the status change
				$this->wikiRequestManager->tryExecuteQueryBuilder();
			}
		}

		return true;
	}

	private function canAutoApprove(): bool {
		if ( (int)$this->config->get( ConfigNames::AIThreshold ) <= 0 ) {
			/*
			 * Extra safeguard to ensure auto-approval does not occur when AIThreshold is:
			 *  - Set to 0 or any negative value
			 *  - A non-numeric string (which casts to 0)
			 *  - null or false (both cast to 0)
			 *
			 * Note: This check does not cover cases where AIThreshold is a positive numeric string,
			 * as those will be cast to integers. However, this is such an edge case
			 * and a case that would mean total misconfiguration of AIThreshold that
			 * we don't actually care about it.
			 *
			 * While this should not be necessary in theory, it is included for added safety.
			 *
			 * TODO: Perhaps this should throw a ConfigException?
			 */
			return false;
		}

		$filter = CreateWikiRegexConstraint::regexFromArray(
			$this->config->get( ConfigNames::AutoApprovalFilter ), '/(', ')+/',
			ConfigNames::AutoApprovalFilter
		);

		if ( preg_match( $filter, strtolower( $this->reason ) ) ) {
			return false;
		}

		return true;
	}
}
