<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class CreateWikiMigrateServerName extends Maintenance {
	public function __construct() {
		parent::__construct();
	}

	public function execute() {
		global $wgCreateWikiDatabase;

		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );

		$res = $dbw->select(
			'cw_wikis',
			[
				'wiki_dbname',
				'wiki_settings',
			]
		);

		foreach ( $res as $row ) {
			$settingsArray = json_decode( $row->wiki_settings, true );

			if ( isset( $settingsArray['wgServer'] ) ) {
				$dbw->update(
					'cw_wikis',
					[
						'wiki_url' => $settingsArray['wgServer']
					],
					[
						'wiki_dbname' => $row->wiki_dbname
					]
				);
			}

		}
	}
}

$maintClass = 'CreateWikiMigrateServerName';
require_once RUN_MAINTENANCE_IF_MAIN;
