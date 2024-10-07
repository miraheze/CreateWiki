<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use Miraheze\CreateWiki\ConfigNames;
use Wikimedia\Rdbms\SelectQueryBuilder;

class PopulateWikiCreation extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Populates wiki_creation column in cw_wikis table' );
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$dbw = $this->getDB( DB_PRIMARY, [], $this->getConfig()->get( ConfigNames::Database ) );

		$res = $dbw->newSelectQueryBuilder()
			->select( '*' )
			->from( 'cw_wikis' )
			->caller( __METHOD__ )
			->fetchResultSet();

		if ( !$res->numRows() ) {
			$this->fatalError( 'No rows found.' );
		}

		foreach ( $res as $row ) {
			$dbname = $row->wiki_dbname;

			$dbw->selectDomain( $this->getConfig()->get( ConfigNames::GlobalWiki ) );

			$res = $dbw->newSelectQueryBuilder()
				->select( 'log_timestamp' )
				->from( 'logging' )
				->where( [
					'log_action' => 'createwiki',
					'log_params' => serialize( [ '4::wiki' => $dbname ] ),
				] )
				->orderBy( 'log_timestamp', SelectQueryBuilder::SORT_DESC )
				->caller( __METHOD__ )
				->fetchRow();

			$dbw->selectDomain( $this->getConfig()->get( ConfigNames::Database ) );

			if ( !isset( $res ) || !isset( $res->log_timestamp ) ) {
				$this->output( "ERROR: couldn't determine when {$dbname} was created!\n" );
				continue;
			}

			$dbw->newUpdateQueryBuilder()
				->update( 'cw_wikis' )
				->set( [ 'wiki_creation' => $res->log_timestamp ] )
				->where( [ 'wiki_dbname' => $dbname ] )
				->caller( __METHOD__ )
				->execute();

			$this->output( "Inserted {$res->log_timestamp} into wiki_creation column for db {$dbname}\n" );
		}
	}
}

$maintClass = PopulateWikiCreation::class;
require_once RUN_MAINTENANCE_IF_MAIN;
