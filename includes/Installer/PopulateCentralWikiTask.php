<?php

namespace Miraheze\CreateWiki\Installer;

use MediaWiki\Installer\Task\Task;
use MediaWiki\MainConfigNames;
use MediaWiki\Status\Status;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use function array_key_first;

class PopulateCentralWikiTask extends Task {

	private CreateWikiDatabaseUtils $databaseUtils;

	public function getName(): string {
		return 'createwiki-populate-central-wiki';
	}

	public function getDescription(): string {
		return '[CreateWiki] Populating central wiki';
	}

	public function getDependencies(): array {
		return [ 'extension-tables', 'services' ];
	}

	private function initServices(): void {
		$services = $this->getServices();
		$this->databaseUtils = $services->get( 'CreateWikiDatabaseUtils' );
	}

	public function execute(): Status {
		$this->initServices();
		$centralWiki =  $this->databaseUtils->getCentralWikiID();
		$dbw = $this->databaseUtils->getGlobalPrimaryDB();

		$exists = $dbw->newSelectQueryBuilder()
			->select( 'wiki_dbname' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_dbname' => $centralWiki ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $exists ) {
			// The central wiki is already populated.
			return Status::newGood();
		}

		$dbw->newInsertQueryBuilder()
			->insertInto( 'cw_wikis' )
			->row( [
				'wiki_dbname' => $centralWiki,
				'wiki_dbcluster' => $this->getDefaultCluster(),
				'wiki_sitename' => 'Central Wiki',
				'wiki_language' => $this->getConfigVar( MainConfigNames::LanguageCode ),
				'wiki_private' => 0,
				'wiki_creation' => $dbw->timestamp(),
				'wiki_category' => 'uncategorised',
			] )
			->caller( __METHOD__ )
			->execute();

		return Status::newGood();
	}

	private function getDefaultCluster(): ?string {
		$clusters = $this->getConfigVar( ConfigNames::DatabaseClusters );
		return (string)array_key_first( $clusters ) ?: null;
	}
}
