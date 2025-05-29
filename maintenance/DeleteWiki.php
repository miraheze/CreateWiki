<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\Services\WikiManagerFactory;

class DeleteWiki extends Maintenance {

	private WikiManagerFactory $wikiManagerFactory;

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Deletes a single wiki. Does not drop databases.' );

		$this->addOption( 'deletewiki', 'Specify the database name to delete.', false, true );
		$this->addOption( 'delete', 'Actually performs deletion and not outputs the wiki to be deleted.', false );

		$this->requireExtension( 'CreateWiki' );
	}

	private function initServices(): void {
		$services = $this->getServiceContainer();
		$this->wikiManagerFactory = $services->get( 'WikiManagerFactory' );
	}

	public function execute(): void {
		$this->initServices();
		$dbname = $this->getOption( 'deletewiki' );
		if ( !$dbname ) {
			$this->fatalError( 'Please specify the database to delete using the --deletewiki option.' );
		}

		if ( $this->hasOption( 'delete' ) ) {
			$wikiManager = $this->wikiManagerFactory->newInstance( $dbname );
			$delete = $wikiManager->delete( force: true );
			if ( $delete ) {
				$this->fatalError( $delete );
			}

			$this->output( "Wiki $dbname deleted.\n" );
		} else {
			$this->output( "Wiki $dbname would be deleted. Use --delete to actually perform deletion.\n" );
		}
	}
}

// @codeCoverageIgnoreStart
return DeleteWiki::class;
// @codeCoverageIgnoreEnd
