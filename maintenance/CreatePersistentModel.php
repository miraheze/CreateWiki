<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\WikiRequestManager;
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
		$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
		$dbr = $databaseUtils->getCentralWikiReplicaDB();

		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'cw_comment', 'cw_status' ] )
			->from( 'cw_requests' )
			->where( [
				'cw_visibility' => WikiRequestManager::VISIBILITY_PUBLIC,
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
			$comment = strtolower( $row->cw_comment );
			if ( !in_array( $comment, $comments ) ) {
				$comments[] = $comment;
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

// @codeCoverageIgnoreStart
return CreatePersistentModel::class;
// @codeCoverageIgnoreEnd
