<?php
require_once '/srv/mediawiki/w/maintenance/commandLine.inc';

if ( $wgDBname !== 'metawiki' ) {
	throw new MWException( 'The DBname used for this script must be metawiki.' );
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

foreach ( $res as $row ) {
	$DBname = $row->wiki_dbname;
	$siteName = $row->wiki_sitename;
	$language = $row->wiki_language;
	$logo = $row->wiki_logo;
	$favicon = $row->wiki_favicon;
	$private = $row->wiki_private;
	$closed = $row->wiki_closed;

	$allWikis[] = "$DBname|$siteName|$language|$logo|$favicon|";

	$cdb = \Cdb\Writer::open( '/srv/mediawiki/cdb-config/' . $DBname . '.cdb' );

	$cdb->set( 'sitename', $siteName );
	$cdb->set( 'language', $language );
	$cdb->set( 'logo', $logo );
	$cdb->set( 'favicon', $favicon );

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

	$cdb->close();
}

file_put_contents( "$DBlistPath/all.dblist", implode( "\n", $allWikis ), LOCK_EX );
file_put_contents( "$DBlistPath/private.dblist", implode( "\n", $privateWikis ), LOCK_EX );
file_put_contents( "$DBlistPath/closed.dblist", implode( "\n", $closedWikis ), LOCK_EX );
