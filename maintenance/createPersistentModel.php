<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

use MediaWiki\MediaWikiServices;
use Phpml\Classification\SVC;
use Phpml\SupportVectorMachine\Kernel;
use Phpml\ModelManager;

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
				'cw_id >= ' . 2000
			],
			__METHOD__
		);
		
		$comments = [];
		$status = [];
		
		foreach ( $res as $row ) {
			if ( $row->cw_status != 'inreview' && !in_array( $row->cw_comment, $comments ) ) {
				$comments[] = $row->cw_comment;
				$status[] = $row->cw_status;
			}
		}
		
		$classifier = new SVC(
			Kernel::LINEAR,
			1.0,
			3,
			null,
			0.0,
			0.001,
			50,
			true,
			true
		);
		
		$classifier->train( $comments, $status );
		
		$modelManager = new ModelManager();
		$modelManager->saveToFile( $classifer, $config->get( 'CreateWikiPersistentModelFile' ) );
    
	}
}

$maintClass = 'CreateWikiCreatePersistentModel';
require_once RUN_MAINTENANCE_IF_MAIN;
