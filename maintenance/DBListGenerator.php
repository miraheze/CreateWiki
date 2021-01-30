<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

use MediaWiki\MediaWikiServices;

class CreateWikiDBListGenerator extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$dbw = wfGetDB( DB_MASTER, [], $config->get( 'CreateWikiDatabase' ) );

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

			if ( (int)$deleted === "0" ) {
				$siteName = $row->wiki_sitename;
				$language = $row->wiki_language;
				$private = $row->wiki_private;
				$closed = $row->wiki_closed;
				$inactive = $row->wiki_inactive;
				$deleted = $row->wiki_deleted;

				$row = $dbw->selectRow(
					'mw_settings',
					'*',
					[
						's_dbname' => $DBname
					],
					__METHOD__
				);
				$extensions = '';
				$settings = '';
				if ( $row ) {
					$extensions = $row->s_extensions;
					$settings = $row->s_settings;
				}

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

		file_put_contents( $config->get( 'CreateWikiDBDirectory' ) . '/all.dblist.tmp', implode( "\n", $allWikis ), LOCK_EX );
		file_put_contents( $config->get( 'CreateWikiDBDirectory' ) . '/private.dblist.tmp', implode( "\n", $privateWikis ), LOCK_EX );
		file_put_contents( $config->get( 'CreateWikiDBDirectory' ) . '/closed.dblist.tmp', implode( "\n", $closedWikis ), LOCK_EX );
		file_put_contents( $config->get( 'CreateWikiDBDirectory' ) . '/inactive.dblist.tmp', implode( "\n", $inactiveWikis ), LOCK_EX );
		file_put_contents( $config->get( 'CreateWikiDBDirectory' ) . '/deleted.dblist.tmp', implode( "\n", $deletedWikis ), LOCK_EX );

		rename( $config->get( 'CreateWikiDBDirectory' ) . '/all.dblist.tmp', $config->get( 'CreateWikiDBDirectory' ) . '/all.dblist' );
		rename( $config->get( 'CreateWikiDBDirectory' ) . '/private.dblist.tmp', $config->get( 'CreateWikiDBDirectory' ) . '/private.dblist' );
		rename( $config->get( 'CreateWikiDBDirectory' ) . '/closed.dblist.tmp', $config->get( 'CreateWikiDBDirectory' ) . '/closed.dblist' );
		rename( $config->get( 'CreateWikiDBDirectory' ) . '/inactive.dblist.tmp', $config->get( 'CreateWikiDBDirectory' ) . '/inactive.dblist' );
		rename( $config->get( 'CreateWikiDBDirectory' ) . '/deleted.dblist.tmp', $config->get( 'CreateWikiDBDirectory' ) . '/deleted.dblist' );
	}
}

$maintClass = 'CreateWikiDBListGenerator';
require_once RUN_MAINTENANCE_IF_MAIN;
