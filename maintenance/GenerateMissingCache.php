<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\ConfigNames;

class GenerateMissingCache extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Generates CreateWiki cache files for all wikis that are currently missing one.' );
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$dataFactory = $this->getServiceContainer()->get( 'CreateWikiDataFactory' );

		foreach ( $this->getConfig()->get( MainConfigNames::LocalDatabases ) as $db ) {
			if ( file_exists( $this->getConfig()->get( ConfigNames::CacheDirectory ) . '/' . $db . '.php' ) ) {
				continue;
			}

			$data = $dataFactory->newInstance( $db );
			$data->resetWikiData( isNewChanges: true );

			$this->output( "Cache generated for {$db}\n" );
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = GenerateMissingCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
