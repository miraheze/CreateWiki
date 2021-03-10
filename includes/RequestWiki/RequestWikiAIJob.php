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
			$tokenDescription = (array)strtolower( $this->description );
			$pipeline->transform( $tokenDescription );
			$approveScore = $pipeline->getEstimator()->predictProbability( $tokenDescription )[0]['approved'];

			$wr->addComment( 'Approval Score: ' . (string)round( $approveScore, 2 ), User::newSystemUser( 'CreateWiki Extension' ) );
			
			if ( is_int( $config->get( 'CreateWikiAIThreshold' ) ) && ( (int)round( $approveScore, 2 ) > $config->get( 'CreateWikiAIThreshold' ) ) ) {
				$wr->approve( User::newSystemUser( 'CreateWiki Extension' ) );
			}
				
		}
		
		return true;
	}
}
