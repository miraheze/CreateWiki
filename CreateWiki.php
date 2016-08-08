<?php
if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'CreateWiki' );
	return;
} else {
	die( 'This version requires MediaWiki 1.25+' );
}

// We must declare available rights, otherwise they are not available to other extensions, such as CentralAuth

$wgAvailablePermissions[] = 'createwiki';
$wgAvailablePermissions[] = 'managewiki';
$wgAvailablePermissions[] = 'managewiki-restricted';
