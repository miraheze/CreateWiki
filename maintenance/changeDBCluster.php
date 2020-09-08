<?php

use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class ChangeDBCluster extends Maintenance {
	private $config;
	private $dbw = null;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'db-cluster', 'Sets the wikis requested to a different db cluster.', true, true );
		$this->addOption( 'file', 'Path to file where the wikinames are store. Must be one wikidb name per line. (Optional, fallsback to current dbname)', false, true );
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
	}

	public function execute() {
		$this->dbw = wfGetDB( DB_MASTER, [], $this->config->get( 'CreateWikiDatabase' ) );

		if ( (bool)$this->getOption( 'file' ) ) {
			$file = fopen( $this->getOption( 'file' ), 'r' );
			if ( !$file ) {
				$this->fatalError( "Unable to read file, exiting" );
			}
		} else {
			$this->updateDbCluster( $this->config->get( 'DBname' ) );

			$this->recacheDBListJson();
			$this->recacheWikiJson( $this->config->get( 'DBname' ) );
			return;
		}

		for ( $linenum = 1; !feof( $file ); $linenum++ ) {
			$line = trim( fgets( $file ) );
			if ( $line == '' ) {
				continue;
			}

			$this->updateDbCluster( $line );
			$this->recacheWikiJson( $line );
		}

		$this->recacheDBListJson();
	}

	private function updateDbCluster( string $wiki ) {
		$this->dbw->update(
			'cw_wikis',
			[
				'wiki_dbcluster' => (string)$this->getOption( 'db-cluster' ),
			],
			[
				'wiki_dbname' => $wiki,
			],
			__METHOD__
		);
	}

	private function recacheWikiJson( string $wiki ) {
		$cWJ = new CreateWikiJson( $wiki );
		$cWJ->resetWiki();
		$cWJ->update();
	}

	private function recacheDBListJson() {
		$cWJ = new CreateWikiJson( $this->config->get( 'CreateWikiGlobalWiki' ) );
		$cWJ->resetDatabaseList();
		$cWJ->update();
	}
}

$maintClass = 'ChangeDBCluster';
require_once RUN_MAINTENANCE_IF_MAIN;
