<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\LoggedUpdateMaintenance;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use function array_key_first;

class PopulateCentralWiki extends LoggedUpdateMaintenance {

	private CreateWikiDatabaseUtils $databaseUtils;

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Populates the initial central wiki into cw_wikis.' );

		$this->addOption( 'category', 'The default category to use for the central wiki.' );
		$this->addOption( 'dbcluster', 'The cluster to create the central wiki database at.' );
		$this->addOption( 'language', 'The default language to use for the central wiki.' );
		$this->addOption( 'sitename', 'The default sitename to use for the central wiki.' );

		$this->requireExtension( 'CreateWiki' );
	}

	private function initServices(): void {
		$services = $this->getServiceContainer();
		$this->databaseUtils = $services->get( 'CreateWikiDatabaseUtils' );
	}

	protected function getUpdateKey(): string {
		$this->initServices();
		return __CLASS__ . ':' . $this->getCentralWiki();
	}

	protected function doDBUpdates(): bool {
		$this->initServices();
		$centralWiki = $this->getCentralWiki();
		$dbw = $this->databaseUtils->getGlobalPrimaryDB();

		$exists = $dbw->newSelectQueryBuilder()
			->select( 'wiki_dbname' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_dbname' => $centralWiki ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $exists ) {
			// The central wiki is already populated.
			return true;
		}

		$dbw->newInsertQueryBuilder()
			->insertInto( 'cw_wikis' )
			->row( [
				'wiki_dbname' => $centralWiki,
				'wiki_dbcluster' => $this->getOption( 'dbcluster', $this->getDefaultCluster() ),
				'wiki_sitename' => $this->getOption( 'sitename', 'Central Wiki' ),
				'wiki_language' => $this->getOption( 'language',
					$this->getConfig()->get( MainConfigNames::LanguageCode )
				),
				'wiki_private' => 0,
				'wiki_creation' => $dbw->timestamp(),
				'wiki_category' => $this->getOption( 'category', 'uncategorised' ),
			] )
			->caller( __METHOD__ )
			->execute();

		$this->output( "Populated central wiki '{$centralWiki}' into cw_wikis.\n" );
		return true;
	}

	private function getCentralWiki(): string {
		return $this->databaseUtils->getCentralWikiID();
	}

	private function getDefaultCluster(): ?string {
		$clusters = $this->getConfig()->get( ConfigNames::DatabaseClusters );
		return (string)array_key_first( $clusters ) ?: null;
	}
}

// @codeCoverageIgnoreStart
return PopulateCentralWiki::class;
// @codeCoverageIgnoreEnd
