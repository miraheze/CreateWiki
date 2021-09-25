<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;

class MigrateServerName extends Maintenance {
	public function __construct() {
		parent::__construct();
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$dbw = wfGetDB( DB_PRIMARY, [], $config->get( 'CreateWikiDatabase' ) );

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

$maintClass = MigrateServerName::class;
require_once RUN_MAINTENANCE_IF_MAIN;
