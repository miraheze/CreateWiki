<?php

require_once( __DIR__ . '/../../../maintenance/Maintenance.php' );

class CheckLastWikiActivity extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Calculates the timestamp of the last meaningful contribution to the wiki.';
	}

	public function execute() {
		$dbr = wfGetDB( DB_REPLICA );

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

		$this->output( (int)$timeStamp );
	}
}

$maintClass = 'CheckLastWikiActivity';
require_once RUN_MAINTENANCE_IF_MAIN;
