<?php
class CreateWikiHooks {
	public static function fnCreateWikiSchemaUpdates( DatabaseUpdater $updater ) {
		global $wgCreateWikiDatabase, $wgDBname;

		if ( $wgCreateWikiDatabase === $wgDBname ) {
			$updater->addExtensionTable( 'cw_requests',
				__DIR__ . '/../sql/cw_requests.sql' );
			$updater->addExtensionTable( 'cw_wikis',
				__DIR__ . '/../sql/cw_wikis.sql' );
			$updater->addExtensionTable( 'cw_comments',
				__DIR__ . '/../sql/cw_comments.sql' );
		}

		return true;
	}

	public static function onRegistration() {
		global $wgLogTypes;

		if ( !in_array( 'farmer', $wgLogTypes ) ) {
			$wgLogTypes[] = 'farmer';
		}
	}
}
