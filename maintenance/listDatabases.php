<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use MediaWiki\MainConfigNames;

class ListDatabases extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Lists all databases known by the wiki farm.' );
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		foreach ( $this->getConfig()->get( MainConfigNames::LocalDatabases ) as $db ) {
			$this->output( "$db\n" );
		}
	}
}

$maintClass = ListDatabases::class;
require_once RUN_MAINTENANCE_IF_MAIN;
