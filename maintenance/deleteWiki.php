<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;

class DeleteWiki extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Deletes a single wiki. Does not drop databases.' );

		$this->addOption( 'deletewiki', 'Specify the database name to delete.', false, true );
		$this->addOption( 'delete', 'Actually performs deletion and not outputs the wiki to be deleted.', false );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$dbname = $this->getOption( 'deletewiki' );

		if ( !$dbname ) {
			$this->fatalError( 'Please specify the database to delete using the --deletewiki option.' );
		}

		if ( $this->hasOption( 'delete' ) ) {
			$wm = $this->getServiceContainer()->get( 'WikiManagerFactory' )
				->newInstance( $dbname );

			$delete = $wm->delete( force: true );

			if ( $delete ) {
				$this->fatalError( $delete );
			}

			$this->output( "Wiki $dbname deleted.\n" );
		} else {
			$this->output( "Wiki $dbname would be deleted. Use --delete to actually perform deletion.\n" );
		}
	}
}

$maintClass = DeleteWiki::class;
require_once RUN_MAINTENANCE_IF_MAIN;
