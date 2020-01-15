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
			[],
			__METHOD__
		);

		if ( !$res || !is_object( $res ) ) {
			throw new MWException( '$res was not set to a valid array.' );
		}

		$allWikis = [];
		$privateWikis = [];
		$closedWikis = [];
		$inactiveWikis = [];
		$deletedWikis = [];

		foreach ( $res as $row ) {
			$DBname = $row->wiki_dbname;
			$siteName = $row->wiki_sitename;
			$language = $row->wiki_language;
			$private = $row->wiki_private;
			$closed = $row->wiki_closed;
			$inactive = $row->wiki_inactive;
			$extensions = $row->wiki_extensions;
			$settings = $row->wiki_settings;

			if ( $row->wiki_deleted === 0 ) {
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
			} else {
				$deletedWikis[] = $DBname;
			}
		}

		file_put_contents( "$wgCreateWikiDBDirectory/all.dblist.tmp", implode( "\n", $allWikis ), LOCK_EX );
		file_put_contents( "$wgCreateWikiDBDirectory/private.dblist.tmp", implode( "\n", $privateWikis ), LOCK_EX );
		file_put_contents( "$wgCreateWikiDBDirectory/closed.dblist.tmp", implode( "\n", $closedWikis ), LOCK_EX );
		file_put_contents( "$wgCreateWikiDBDirectory/inactive.dblist.tmp", implode( "\n", $inactiveWikis ), LOCK_EX );
		file_put_contents( "$wgCreateWikiDBDirectory/deleted.dblist.tmp", implode( "\n", $deletedWikis ), LOCK_EX );

		rename( "$wgCreateWikiDBDirectory/all.dblist.tmp", "$wgCreateWikiDBDirectory/all.dblist" );
		rename( "$wgCreateWikiDBDirectory/private.dblist.tmp", "$wgCreateWikiDBDirectory/private.dblist" );
		rename( "$wgCreateWikiDBDirectory/closed.dblist.tmp", "$wgCreateWikiDBDirectory/closed.dblist" );
		rename( "$wgCreateWikiDBDirectory/inactive.dblist.tmp", "$wgCreateWikiDBDirectory/inactive.dblist" );
		rename( "$wgCreateWikiDBDirectory/deleted.dblist.tmp", "$wgCreateWikiDBDirectory/deleted.dblist" );
	}
}

$maintClass = 'CreateWikiDBListGenerator';
require_once RUN_MAINTENANCE_IF_MAIN;
