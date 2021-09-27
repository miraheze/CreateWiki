<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use Phpml\Classification\SVC;
use Phpml\FeatureExtraction\StopWords\English;
use Phpml\FeatureExtraction\TokenCountVectorizer;
use Phpml\ModelManager;
use Phpml\Pipeline;
use Phpml\SupportVectorMachine\Kernel;
use Phpml\Tokenization\WordTokenizer;

class CreatePersistentModel extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$dbr = wfGetDB( DB_REPLICA, [], $config->get( 'CreateWikiGlobalWiki' ) );

		$res = $dbr->select(
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
				Kernel::RBF,
				1.0,
				3,
				0.1,
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

$maintClass = CreatePersistentModel::class;
require_once RUN_MAINTENANCE_IF_MAIN;
