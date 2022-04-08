<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\RemoteWiki;

class ChangeDBCluster extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addOption( 'db-cluster', 'Sets the wikis requested to a different db cluster.', true, true );
		$this->addOption( 'file', 'Path to file where the wikinames are store. Must be one wikidb name per line. (Optional, fallsback to current dbname)', false, true );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$hookRunner = MediaWikiServices::getInstance()->get( 'CreateWikiHookRunner' );

		if ( (bool)$this->getOption( 'file' ) ) {
			$file = fopen( $this->getOption( 'file' ), 'r' );

			if ( !$file ) {
				$this->fatalError( 'Unable to read file, exiting' );
			}
		} else {
			$wiki = new RemoteWiki( $hookRunner, $config->get( 'DBname' ) );
			$wiki->setDBCluster( $this->getOption( 'db-cluster' ) );
			$wiki->commit();

			return;
		}

		for ( $linenum = 1; !feof( $file ); $linenum++ ) {
			$line = trim( fgets( $file ) );

			if ( $line == '' ) {
				continue;
			}

			$wiki = new RemoteWiki( $hookRunner, $line );
			$wiki->setDBCluster( $this->getOption( 'db-cluster' ) );
			$wiki->commit();
		}
	}
}

$maintClass = ChangeDBCluster::class;
require_once RUN_MAINTENANCE_IF_MAIN;
