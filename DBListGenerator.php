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

// Truncate the lists first
foreach ( array( 'all.dblist', 'private.dblist', 'closed.dblist' ) as $DBlist ) {
        file_put_contents( "$DBlistPath/$DBlist", '' );
}

foreach ( $res as $row ) {
        $DBname = $row->wiki_dbname;
        $siteName = $row->wiki_sitename;
        $language = $row->wiki_language;
        $private = $row->wiki_private;
        $closed = $row->wiki_closed;

        $allDBlistString = "$DBname|$siteName|$language|\n";

        file_put_contents( "$DBlistPath/all.dblist", $allDBlistString, FILE_APPEND | LOCK_EX );

        if ( $private === "1" ) {
                file_put_contents( "$DBlistPath/private.dblist", $DBname . PHP_EOL, FILE_APPEND | LOCK_EX );
        }

        if ( $closed === "1" ) {
                file_put_contents( "$DBlistPath/closed.dblist", $DBname . PHP_EOL, FILE_APPEND | LOCK_EX );
        }
}
