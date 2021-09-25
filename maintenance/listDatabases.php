<?php

require_once( __DIR__ . '/../../../maintenance/Maintenance.php' );

use MediaWiki\MediaWikiServices;

class ListDatabases extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Lists all databases known by the wiki farm.';
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );

		foreach ( $config->get( 'LocalDatabases' ) as $db ) {
			print "$db\n";
		}
	}
}

$maintClass = ListDatabases::class;
require_once RUN_MAINTENANCE_IF_MAIN;
