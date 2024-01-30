<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use Miraheze\CreateWiki\WikiManager;

class DeleteWiki extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Deletes a single wiki. Does not drop databases";
		$this->addOption( 'deletewiki', "Specify the database name to delete", false, true );
		$this->addOption( 'delete', 'Actually performs deletions and not outputs the wiki to be deleted', false );
	}

	public function execute() {
		$dbname = $this->getOption( "deletewiki" );

		if ( empty( $dbname ) ) {
			$this->output( "Please specify the database to delete using the --deletewiki option.\n" );
			return;
		}

		if ( $this->hasOption( 'delete' ) ) {
			$wm = new WikiManager( $dbname );
			$wm->delete( true );
			$this->output( "Wiki $dbname deleted.\n" );
		}
	}
}

// Run the maintenance script
$maintClass = DeleteWiki::class;
require_once RUN_MAINTENANCE_IF_MAIN;
