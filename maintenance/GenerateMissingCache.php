<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\CreateWikiDataFactory;
use function file_exists;

class GenerateMissingCache extends Maintenance {

	private CreateWikiDataFactory $dataFactory;

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Generates CreateWiki cache files for all wikis that are currently missing one.' );
		$this->requireExtension( 'CreateWiki' );
	}

	private function initServices(): void {
		$services = $this->getServiceContainer();
		$this->dataFactory = $services->get( 'CreateWikiDataFactory' );
	}

	public function execute(): void {
		$this->initServices();
		foreach ( $this->getConfig()->get( MainConfigNames::LocalDatabases ) as $db ) {
			if ( file_exists( $this->getConfig()->get( ConfigNames::CacheDirectory ) . '/' . $db . '.php' ) ) {
				continue;
			}

			$data = $this->dataFactory->newInstance( $db );
			$data->resetWikiData( isNewChanges: true );

			$this->output( "Cache generated for {$db}\n" );
		}
	}
}

// @codeCoverageIgnoreStart
return GenerateMissingCache::class;
// @codeCoverageIgnoreEnd
