<?php

use MediaWiki\MediaWikiServices;

require_once( __DIR__ . '/../../../maintenance/Maintenance.php' );

class CreateWikiListDatabases extends Maintenance {
	private $config;

	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Lists all databases known by the wiki farm.';
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
	}

	public function execute() {
		foreach ( $this->config->get( 'LocalDatabases' ) as $db ) {
			print "$db\n";
		}
	}
}

$maintClass = 'CreateWikiListDatabases';
require_once RUN_MAINTENANCE_IF_MAIN;
