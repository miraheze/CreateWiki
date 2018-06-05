<?php
require_once '/srv/mediawiki/w/maintenance/commandLine.inc';

if ( $wgDBname !== $wgCreateWikiDatabase ) {
	throw new MWException( 'The DBname used for this script must be ' . $wgCreateWikiDatabase );
}

$dbw = wfGetDB( DB_MASTER );

$res = $dbw->select(
	'cw_wikis',
	'*',
	array(),
	__METHOD__
);

if ( !$res || !is_object( $res ) ) {
	throw new MWException( '$res was not set to a valid array.' );
}

$DBlistPath = '/srv/mediawiki/dblist';

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

	$cdb = \Cdb\Writer::open( '/srv/mediawiki/cdb-config/' . $DBname . '.cdb' );

	$cdb->set( 'sitename', $siteName );
	$cdb->set( 'language', $language );

	if ( $private === "1" ) {
		$privateWikis[] = $DBname;
		$cdb->set( 'private', 1 );
	} else {
		$cdb->set( 'private', 0 );
	}

	if ( $closed === "1" ) {
		$closedWikis[] = $DBname;
		$cdb->set( 'closed', 1 );
	} else {
		$cdb->set( 'closed', 0 );
	}

	if ( $inactive === "1" ) {
		$inactiveWikis[] = $DBname;
		$cdb->set( 'inactive', 1 );
	} else {
		$cdb->set( 'inactive', 0 );
	}

	$cdb->close();
}

file_put_contents( "$DBlistPath/all.dblist", implode( "\n", $allWikis ), LOCK_EX );
file_put_contents( "$DBlistPath/private.dblist", implode( "\n", $privateWikis ), LOCK_EX );
file_put_contents( "$DBlistPath/closed.dblist", implode( "\n", $closedWikis ), LOCK_EX );
file_put_contents( "$DBlistPath/inactive.dblist", implode( "\n", $inactiveWikis ), LOCK_EX );
