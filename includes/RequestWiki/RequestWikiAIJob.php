<?php

namespace Miraheze\CreateWiki\RequestWiki;

use Job;
use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use Phpml\ModelManager;
use Title;
use User;

class RequestWikiAIJob extends Job {
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'RequestWikiAIJob', $params );
	}

	public function run() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );
		$modelFile = $config->get( 'CreateWikiPersistentModelFile' );
		$hookRunner = MediaWikiServices::getInstance()->get( 'CreateWikiHookRunner' );

		$wr = new WikiRequest( $this->params['id'], $hookRunner );

		$pipeline = '';
		$hookRunner->onCreateWikiReadPersistentModel( $pipeline );

		// @phan-suppress-next-line PhanImpossibleCondition
		if ( $pipeline || ( $modelFile && file_exists( $modelFile ) ) ) {
			if ( !$pipeline ) {
				$modelManager = new ModelManager();
				$pipeline = $modelManager->restoreFromFile( $modelFile );
			}

			$tokenDescription = (array)strtolower( $this->params['description'] );

			// @phan-suppress-next-line PhanUndeclaredMethod
			$pipeline->transform( $tokenDescription );

			// @phan-suppress-next-line PhanUndeclaredMethod
			$approveScore = $pipeline->getEstimator()->predictProbability( $tokenDescription )[0]['approved'];

			$wr->addComment( 'Approval Score: ' . (string)round( $approveScore, 2 ), User::newSystemUser( 'CreateWiki Extension' ) );

			if ( is_int( $config->get( 'CreateWikiAIThreshold' ) ) && ( (int)round( $approveScore, 2 ) > $config->get( 'CreateWikiAIThreshold' ) ) && $this->canAutoApprove( $config ) ) {
				$wr->approve( User::newSystemUser( 'CreateWiki Extension' ) );
			}
		}

		return true;
	}

	private function canAutoApprove( $config ) {
		$descriptionFilter = CreateWikiRegexConstraint::regexFromArray(
			$config->get( 'CreateWikiAutoApprovalFilter' ), '/(', ')+/',
			'CreateWikiAutoApprovalFilter'
		);

		if ( preg_match( $descriptionFilter, strtolower( $this->params['description'] ) ) ) {
			return false;
		}

		return true;
	}
}
