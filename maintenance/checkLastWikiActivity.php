<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;

class CheckLastWikiActivity extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Calculates the timestamp of the last meaningful contribution to the wiki.' );
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$dbr = $this->getDB( DB_REPLICA );

		$row = $dbr->selectRow(
			'recentchanges',
			'rc_timestamp',
			[
				"rc_log_action != 'renameuser'"
			],
			__METHOD__,
			[
				'ORDER BY' => 'rc_timestamp DESC'
			]
		);

		$timeStamp = $row ? $row->rc_timestamp : 0;

		$this->output( (string)$timeStamp );
	}
}

$maintClass = CheckLastWikiActivity::class;
require_once RUN_MAINTENANCE_IF_MAIN;
