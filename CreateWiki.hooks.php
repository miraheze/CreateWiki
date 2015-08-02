<?php
class CreateWikiHooks {
	function fnCreateWikiSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( 'cw_requests',
			__DIR__ . '/cw_requests.sql' );
		return true;
	}
}
