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
	$private = $row->wiki_private;
	$closed = $row->wiki_closed;
	
	$allWikis[] = "$DBname|$siteName|$language|";
	
	if ( $private === "1" ) {
		$privateWikis[] = $DBname;
	}
	
	if ( $closed === "1" ) {
		$closedWikis[] = $DBname;
	}
	
	file_put_contents( "$DBlistPath/all.dblist", implode( "\n", $allWikis ), LOCK_EX );
	file_put_contents( "$DBlistPath/private.dblist", implode( "\n", $privateWikis ), LOCK_EX );
	file_put_contents( "$DBlistPath/closed.dblist", implode( "\n", $closedWikis ), LOCK_EX );
}
