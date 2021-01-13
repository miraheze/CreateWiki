<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

use MediaWiki\MediaWikiServices;
use Phpml\Classification\SVC;
use Phpml\FeatureExtraction\StopWords\English;
use Phpml\FeatureExtraction\TokenCountVectorizer;
use Phpml\ModelManager;
use Phpml\Pipeline;
use Phpml\SupportVectorMachine\Kernel;
use Phpml\Tokenization\WordTokenizer;

class CreateWikiCreatePersistentModel extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$dbw = wfGetDB( DB_REPLICA );

		$res = $dbw->select(
			'cw_requests',
			[
				'cw_comment',
				'cw_status'
			],
			[
				'cw_status' => [
					'approved',
					'declined'
				],
				'cw_language' => 'en'
			],
			__METHOD__,
			[
				'LIMIT' => 2500,
				'ORDER BY' => 'cw_id DESC'
			]
		);
		
		$comments = [];
		$status = [];
		
		foreach ( $res as $row ) {
			if ( !in_array( strtolower( $row->cw_comment ), $comments ) ) {
				$comments[] = strtolower( $row->cw_comment );
				$status[] = $row->cw_status;
			}
		}
		
		$pipeline = new Pipeline(
			[
				new TokenCountVectorizer(
					new WordTokenizer,
					new English
				)
			],
			new SVC(
				Kernel::LINEAR,
				1.0,
				3,
				null,
				0.0,
				0.001,
				50,
				true,
				true
			)
		);
		
		$pipeline->train( $comments, $status );
		
		$modelManager = new ModelManager();
		$modelManager->saveToFile( $pipeline, $config->get( 'CreateWikiPersistentModelFile' ) );
    
	}
}

$maintClass = 'CreateWikiCreatePersistentModel';
require_once RUN_MAINTENANCE_IF_MAIN;
