<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\CreateWikiJson;

class GenerateMissingCache extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->mDescription = 'Generates CreateWiki cache files for all wikis that are currently missing one.';
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$hookRunner = MediaWikiServices::getInstance()->get( 'CreateWikiHookRunner' );

		foreach ( $config->get( 'LocalDatabases' ) as $db ) {
			if ( file_exists( $config->get( 'CreateWikiCacheDirectory' ) . '/' . $db . '.json' ) ) {
				continue;
			}

			$cWJ = new CreateWikiJson( $db, $hookRunner );
			$cWJ->update();

			$this->output( "Cache generated for {$db}\n" );
		}
	}
}

$maintClass = GenerateMissingCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
