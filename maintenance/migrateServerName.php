<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use Miraheze\CreateWiki\ConfigNames;

class MigrateServerName extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$dbw = $this->getDB( DB_PRIMARY, [], $this->getConfig()->get( ConfigNames::Database ) );

		$res = $dbw->newSelectQueryBuilder()
			->select( [ 'wiki_dbname', 'wiki_settings' ] )
			->from( 'cw_wikis' )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$settingsArray = json_decode( $row->wiki_settings, true );

			if ( isset( $settingsArray['wgServer'] ) ) {
				$dbw->newUpdateQueryBuilder()
					->update( 'cw_wikis' )
					->set( [ 'wiki_url' => $settingsArray['wgServer'] ] )
					->where( [ 'wiki_dbname' => $row->wiki_dbname ] )
					->caller( __METHOD__ )
					->execute();
			}

		}
	}
}

$maintClass = MigrateServerName::class;
require_once RUN_MAINTENANCE_IF_MAIN;
