<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;

class PopulateWikiCreation extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Populates wiki_creation column in cw_wikis table' );
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$dbw = $this->getDB( DB_PRIMARY, [], $this->getConfig()->get( 'CreateWikiDatabase' ) );

		$res = $dbw->select(
			'cw_wikis',
			'*',
			[],
			__METHOD__
		);

		if ( !$res || !is_object( $res ) ) {
			$this->fatalError( '$res was not set to a valid array.' );
		}

		foreach ( $res as $row ) {
			$DBname = $row->wiki_dbname;

			$dbw->selectDomain( $this->getConfig()->get( 'CreateWikiGlobalWiki' ) );

			$res = $dbw->selectRow(
				'logging',
				'log_timestamp',
				[
					'log_action' => 'createwiki',
					'log_params' => serialize( [ '4::wiki' => $DBname ] )
				],
				__METHOD__,
				[
					'ORDER BY' => 'log_timestamp DESC'
				]
			);

			$dbw->selectDomain( $this->getConfig()->get( 'CreateWikiDatabase' ) );

			if ( !isset( $res ) || !isset( $res->log_timestamp ) ) {
				$this->output( "ERROR: couldn't determine when {$DBname} was created!\n" );
				continue;
			}

			$dbw->update(
				'cw_wikis',
				[
					'wiki_creation' => $res->log_timestamp,
				],
				[
					'wiki_dbname' => $DBname
				],
				__METHOD__
			);

			$this->output( "Inserted {$res->log_timestamp} into wiki_creation column for db {$DBname}\n" );
		}
	}
}

$maintClass = PopulateWikiCreation::class;
require_once RUN_MAINTENANCE_IF_MAIN;
