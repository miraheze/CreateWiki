<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;

class CreateWikiPopulateWikiDescriptions extends Maintenance {
	public function __construct() {
		parent::__construct();
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$dbw = wfGetDB( DB_MASTER, [], $config->get( 'CreateWikiDatabase' ) );

		$res = $dbw->select(
			'cw_requests',
			[
				'cw_dbname',
				'cw_comment'
			]
		);

		foreach ( $res as $row ) {
			$dbw->update(
				'cw_wikis',
				[
					'wiki_description' => $row->cw_comment
				],
				[
					'wiki_dbname' => $row->cw_dbname
				]
			);
		}
	}
}

$maintClass = 'CreateWikiPopulateWikiDescriptions';
require_once RUN_MAINTENANCE_IF_MAIN;
