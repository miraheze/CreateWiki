<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use MediaWiki\MediaWikiServices;

class ListDatabases extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Lists all databases known by the wiki farm.' );
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );

		foreach ( $config->get( 'LocalDatabases' ) as $db ) {
			$this->output( "$db\n" );
		}
	}
}

$maintClass = ListDatabases::class;
require_once RUN_MAINTENANCE_IF_MAIN;
