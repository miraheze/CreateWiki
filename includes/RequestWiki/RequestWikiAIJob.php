<?php

use MediaWiki\MediaWikiServices;
use Phpml\ModelManager;

class RequestWikiAIJob extends Job {
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'RequestWikiAIJob', $params );
	}

	public function run() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$modelFile = $config->get( 'CreateWikiPersistentModelFile' );

		$wr = new WikiRequest( $this->params['id'] );

		if ( file_exists( $modelFile ) ) {
			$modelManager = new ModelManager();
			$pipeline = $modelManager->restoreFromFile( $modelFile );
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
		$descriptionBlacklist = '/(' . implode( '|', $config->get( 'CreateWikiAutoApprovalBlacklist' ) ) . ')+/';
		if ( preg_match( $descriptionBlacklist, strtolower( $this->params['description'] ) ) ) {
			return false;
		}

		return true;
	}
}
