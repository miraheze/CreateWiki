<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use Miraheze\CreateWiki\ConfigNames;
use Phpml\Classification\SVC;
use Phpml\FeatureExtraction\StopWords\English;
use Phpml\FeatureExtraction\TokenCountVectorizer;
use Phpml\ModelManager;
use Phpml\Pipeline;
use Phpml\SupportVectorMachine\Kernel;
use Phpml\Tokenization\WordTokenizer;
use Wikimedia\Rdbms\SelectQueryBuilder;

class CreatePersistentModel extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$dbr = $this->getDB( DB_REPLICA, [],
			$this->getConfig()->get( ConfigNames::GlobalWiki )
		);

		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'cw_comment', 'cw_status' ] )
			->from( 'cw_requests' )
			->where( [
				'cw_status' => [ 'approved', 'declined' ],
				'cw_language' => 'en',
			] )
			->orderBy( 'cw_id', SelectQueryBuilder::SORT_DESC )
			->limit( 2500 )
			->caller( __METHOD__ )
			->fetchResultSet();

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

		$hookRunner = $this->getServiceContainer()->get( 'CreateWikiHookRunner' );
		if ( !$hookRunner->onCreateWikiWritePersistentModel( serialize( $pipeline ) ) ) {
			$modelManager = new ModelManager();
			$modelManager->saveToFile(
				$pipeline,
				$this->getConfig()->get( ConfigNames::PersistentModelFile )
			);
		}
	}
}

$maintClass = CreatePersistentModel::class;
require_once RUN_MAINTENANCE_IF_MAIN;
