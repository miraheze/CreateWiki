<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use LoggedUpdateMaintenance;
use MediaWiki\MainConfigNames;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\ConfigNames;

class PopulateGlobalWiki extends LoggedUpdateMaintenance {

	public function __construct() {
		parent::__construct();

		$this->addOption( 'category', 'The default category to use for the global wiki.' );
		$this->addOption( 'dbcluster', 'The cluster to create the global wiki database at.' );
		$this->addOption( 'language', 'The default language to use for the global wiki.' );
		$this->addOption( 'sitename', 'The default sitename to use for the global wiki.' );
		$this->requireExtension( 'CreateWiki' );
	}

	protected function getUpdateKey(): string {
		return __CLASS__;
	}

	protected function updateSkippedMessage(): string {
		return 'The global wiki has already been populated in cw_wikis.';
	}

	protected function doDBUpdates(): bool {
		$globalWiki = $this->getConfig()->get( ConfigNames::GlobalWiki );

		$connectionProvider = $this->getServiceContainer()->getConnectionProvider();
		$dbw = $connectionProvider->getPrimaryDatabase(
			$this->getConfig()->get( ConfigNames::Database )
		);

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
				'wiki_dbcluster' => $this->getOption( 'dbcluster', null ),
				'wiki_sitename' => $this->getOption(
					'sitename',
					WikiMap::getWikiName( $globalWiki )
				),
				'wiki_language' => $this->getOption(
					'language',
					$this->getConfig()->get( MainConfigNames::LanguageCode )
				),
				'wiki_private' => 0,
				'wiki_creation' => $dbw->timestamp(),
				'wiki_category' => $this->getOption( 'category', 'uncategorised' ),
			] )
			->caller( __METHOD__ )
			->execute();

		$this->output( "Populated global wiki {$globalWiki} into cw_wikis\n" );

		return true;
	}
}

$maintClass = PopulateGlobalWiki::class;
require_once RUN_MAINTENANCE_IF_MAIN;
