<?php
if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'CreateWiki' );
	return;
} else {
	die( 'This version requires MediaWiki 1.25+' );
}
