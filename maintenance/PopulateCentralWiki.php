<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\LoggedUpdateMaintenance;
use Miraheze\CreateWiki\ConfigNames;

class PopulateCentralWiki extends LoggedUpdateMaintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Populates the initial central wiki into cw_wikis.' );

		$this->addOption( 'category', 'The default category to use for the central wiki.' );
		$this->addOption( 'dbcluster', 'The cluster to create the central wiki database at.' );
		$this->addOption( 'language', 'The default language to use for the central wiki.' );
		$this->addOption( 'sitename', 'The default sitename to use for the central wiki.' );

		$this->requireExtension( 'CreateWiki' );
	}

	protected function getUpdateKey(): string {
		return __CLASS__ . ':' . $this->getCentralWiki();
	}

	protected function doDBUpdates(): bool {
		$centralWiki = $this->getCentralWiki();

		$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
		$dbw = $databaseUtils->getGlobalPrimaryDB();

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
		$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
		return $databaseUtils->getCentralWikiID();
	}

	private function getDefaultCluster(): ?string {
		$clusters = $this->getConfig()->get( ConfigNames::DatabaseClusters );
		return array_key_first( $clusters );
	}
}

// @codeCoverageIgnoreStart
return PopulateCentralWiki::class;
// @codeCoverageIgnoreEnd
