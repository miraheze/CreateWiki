<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP ??= getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;

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
