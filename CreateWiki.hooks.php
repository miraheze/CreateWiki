<?php
class CreateWikiHooks {
	public static function fnCreateWikiSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( 'cw_requests',
			__DIR__ . '/cw_requests.sql' );
		$updater->addExtensionTable( 'cw_wikis',
			__DIR__ . '/cw_wikis.sql' );

		return true;
	}

	public static function onRegistration() {
		global $wgLogTypes;

		if ( !in_array( 'farmer', $wgLogTypes ) ) {
			$wgLogTypes[] = 'farmer';
		}
	}
}
