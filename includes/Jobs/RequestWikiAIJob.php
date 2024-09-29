<?php

namespace Miraheze\CreateWiki\Jobs;

use Job;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\User\User;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Phpml\ModelManager;

class RequestWikiAIJob extends Job {

	public const JOB_NAME = 'RequestWikiAIJob';

	private Config $config;
	private CreateWikiHookRunner $hookRunner;
	private WikiRequestManager $wikiRequestManager;

	private string $description;
	private int $id;

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

		$this->description = $params['description'];
		$this->id = $params['id'];
	}

	public function run(): bool {
		$this->wikiRequestManager->fromID( $this->id );
		$modelFile = $this->config->get( 'CreateWikiPersistentModelFile' );

		$pipeline = '';
		$this->hookRunner->onCreateWikiReadPersistentModel( $pipeline );

		if ( $pipeline || ( $modelFile && file_exists( $modelFile ) ) ) {
			if ( !$pipeline ) {
				$modelManager = new ModelManager();
				$pipeline = $modelManager->restoreFromFile( $modelFile );
			}

			$tokenDescription = (array)strtolower( $this->description );

			// @phan-suppress-next-line PhanUndeclaredMethod
			$pipeline->transform( $tokenDescription );

			// @phan-suppress-next-line PhanUndeclaredMethod
			$approveScore = $pipeline->getEstimator()->predictProbability( $tokenDescription )[0]['approved'];

			$this->wikiRequestManager->addComment(
				comment: 'Approval Score: ' . (string)round( $approveScore, 2 ),
				user: User::newSystemUser( 'CreateWiki Extension' ),
				log: false,
				type: 'comment'
			);

			if (
				is_int( $this->config->get( 'CreateWikiAIThreshold' ) ) &&
				( (int)round( $approveScore, 2 ) > $this->config->get( 'CreateWikiAIThreshold' ) ) &&
				$this->canAutoApprove()
			) {
				$this->wikiRequestManager->approve(
					user: User::newSystemUser( 'CreateWiki Extension' ),
					comment: ''
				);
			}
		}

		return true;
	}

	private function canAutoApprove(): bool {
		$descriptionFilter = CreateWikiRegexConstraint::regexFromArray(
			$this->config->get( 'CreateWikiAutoApprovalFilter' ), '/(', ')+/',
			'CreateWikiAutoApprovalFilter'
		);

		if ( preg_match( $descriptionFilter, strtolower( $this->description ) ) ) {
			return false;
		}

		return true;
	}
}
