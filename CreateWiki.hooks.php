<?php
class CreateWikiHooks {
	function fnCreateWikiSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( 'cw_requests',
			__DIR__ . '/cw_requests.sql' );
		$updater->addExtensionTable( 'cw_wikis',
			__DIR__ . '/cw_wikis.sql' );

		return true;
	}
}
