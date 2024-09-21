<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use MediaWiki\MainConfigNames;
use Miraheze\CreateWiki\CreateWikiJson;
use Miraheze\CreateWiki\CreateWikiPHPCache;

class GenerateMissingCache extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Generates CreateWiki cache files for all wikis that are currently missing one.' );
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		foreach ( $this->getConfig()->get( MainConfigNames::LocalDatabases ) as $db ) {
			if ( $this->getConfig()->get( 'CreateWikiUsePhpCache' ) ) {
				if ( file_exists( $this->getConfig()->get( 'CreateWikiCacheDirectory' ) . '/' . $db . '.php' ) ) {
					continue;
				}

				$cache = new CreateWikiPHPCache(
					$db,
					$this->getServiceContainer()->get( 'CreateWikiHookRunner' )
				);

				$cWP->resetWiki();
			} else {
				if ( file_exists( $this->getConfig()->get( 'CreateWikiCacheDirectory' ) . '/' . $db . '.json' ) ) {
					continue;
				}
				$cWJ = new CreateWikiJson(
					$db,
					$this->getServiceContainer()->get( 'CreateWikiHookRunner' )
				);

				$cWJ->update();
			}

			$this->output( "Cache generated for {$db}\n" );
		}
	}
}

$maintClass = GenerateMissingCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
