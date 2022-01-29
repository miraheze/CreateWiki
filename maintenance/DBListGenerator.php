<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;

class DBListGenerator extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$dbr = wfGetDB( DB_REPLICA, [], $config->get( 'CreateWikiDatabase' ) );

		$res = $dbr->select(
			'cw_wikis',
			'wiki_dbname',
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
			if ( $deleted === "0" ) {
				$allWikis[] = $row->wiki_dbname;

				if ( $private === "1" ) {
					$privateWikis[] = $row->wiki_dbname;
				}

				if ( $closed === "1" ) {
					$closedWikis[] = $row->wiki_dbname;
				}

				if ( $inactive === "1" ) {
					$inactiveWikis[] = $row->wiki_dbname;
				}
			} else {
				$deletedWikis[] = $row->wiki_dbname;
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

$maintClass = DBListGenerator::class;
require_once RUN_MAINTENANCE_IF_MAIN;
