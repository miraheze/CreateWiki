<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP ??= getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";

use LoggedUpdateMaintenance;
use MediaWiki\MainConfigNames;
use Miraheze\CreateWiki\ConfigNames;

class PopulateGlobalWiki extends LoggedUpdateMaintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Populates the initial global wiki into cw_wikis.' );

		$this->addOption( 'category', 'The default category to use for the global wiki.' );
		$this->addOption( 'dbcluster', 'The cluster to create the global wiki database at.' );
		$this->addOption( 'language', 'The default language to use for the global wiki.' );
		$this->addOption( 'sitename', 'The default sitename to use for the global wiki.' );

		$this->requireExtension( 'CreateWiki' );
	}

	protected function getUpdateKey(): string {
		return __CLASS__ . ':' . $this->getGlobalWiki();
	}

	protected function doDBUpdates(): bool {
		$globalWiki = $this->getGlobalWiki();

		$connectionProvider = $this->getServiceContainer()->getConnectionProvider();
		$dbw = $connectionProvider->getPrimaryDatabase( 'virtual-createwiki' );

		$exists = $dbw->newSelectQueryBuilder()
			->select( 'wiki_dbname' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_dbname' => $globalWiki ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $exists ) {
			// The global wiki is already populated.
			return true;
		}

		$dbw->newInsertQueryBuilder()
			->insertInto( 'cw_wikis' )
			->row( [
				'wiki_dbname' => $globalWiki,
				'wiki_dbcluster' => $this->getOption( 'dbcluster', $this->getDefaultCluster() ),
				'wiki_sitename' => $this->getOption( 'sitename', 'Global Wiki' ),
				'wiki_language' => $this->getOption( 'language',
					$this->getConfig()->get( MainConfigNames::LanguageCode )
				),
				'wiki_private' => 0,
				'wiki_creation' => $dbw->timestamp(),
				'wiki_category' => $this->getOption( 'category', 'uncategorised' ),
			] )
			->caller( __METHOD__ )
			->execute();

		$this->output( "Populated global wiki '{$globalWiki}' into cw_wikis.\n" );
		return true;
	}

	private function getGlobalWiki(): string {
		$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
		return $databaseUtils->getGlobalWikiID();
	}

	private function getDefaultCluster(): ?string {
		$clusters = $this->getConfig()->get( ConfigNames::DatabaseClusters );
		return $clusters[0] ?? null;
	}
}

$maintClass = PopulateGlobalWiki::class;
require_once RUN_MAINTENANCE_IF_MAIN;
