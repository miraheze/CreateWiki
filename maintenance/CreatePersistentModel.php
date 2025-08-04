<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Phpml\Classification\SVC;
use Phpml\FeatureExtraction\StopWords\English;
use Phpml\FeatureExtraction\TokenCountVectorizer;
use Phpml\ModelManager;
use Phpml\Pipeline;
use Phpml\SupportVectorMachine\Kernel;
use Phpml\Tokenization\WordTokenizer;
use stdClass;
use Wikimedia\Rdbms\SelectQueryBuilder;
use function in_array;
use function serialize;
use function strtolower;

class CreatePersistentModel extends Maintenance {

	private CreateWikiDatabaseUtils $databaseUtils;
	private CreateWikiHookRunner $hookRunner;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'CreateWiki' );
	}

	private function initServices(): void {
		$services = $this->getServiceContainer();
		$this->databaseUtils = $services->get( 'CreateWikiDatabaseUtils' );
		$this->hookRunner = $services->get( 'CreateWikiHookRunner' );
	}

	public function execute(): void {
		$this->initServices();
		$dbr = $this->databaseUtils->getCentralWikiReplicaDB();

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
			if ( !$row instanceof stdClass ) {
				// Skip unexpected row
				continue;
			}

			$comment = strtolower( $row->cw_comment );
			if ( !in_array( $comment, $comments, true ) ) {
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

		if ( !$this->hookRunner->onCreateWikiWritePersistentModel( serialize( $pipeline ) ) ) {
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
