<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class CreateWikiDBListGenerator extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		global $wgCreateWikiDBDirectory, $wgCreateWikiDatabase;

		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );

		$res = $dbw->select(
			'cw_wikis',
			'*',
			array(),
			__METHOD__
		);

		if ( !$res || !is_object( $res ) ) {
			throw new MWException( '$res was not set to a valid array.' );
		}

		$allWikis = array();
		$privateWikis = array();
		$closedWikis = array();
		$inactiveWikis = array();

		foreach ( $res as $row ) {
			$DBname = $row->wiki_dbname;
			$siteName = $row->wiki_sitename;
			$language = $row->wiki_language;
			$private = $row->wiki_private;
			$closed = $row->wiki_closed;
			$inactive = $row->wiki_inactive;
			$extensions = $row->wiki_extensions;
			$settings = $row->wiki_settings;

			$allWikis[] = "$DBname|$siteName|$language|$extensions|$settings|";

			if ( $private === "1" ) {
				$privateWikis[] = $DBname;
			}

			if ( $closed === "1" ) {
				$closedWikis[] = $DBname;
			}

			if ( $inactive === "1" ) {
				$inactiveWikis[] = $DBname;
			}
		}

		file_put_contents( "$wgCreateWikiDBDirectory/all.dblist", implode( "\n", $allWikis ), LOCK_EX );
		file_put_contents( "$wgCreateWikiDBDirectory/private.dblist", implode( "\n", $privateWikis ), LOCK_EX );
		file_put_contents( "$wgCreateWikiDBDirectory/closed.dblist", implode( "\n", $closedWikis ), LOCK_EX );
		file_put_contents( "$wgCreateWikiDBDirectory/inactive.dblist", implode( "\n", $inactiveWikis ), LOCK_EX );
	}
}

$maintClass = 'CreateWikiDBListGenerator';
require_once RUN_MAINTENANCE_IF_MAIN;
