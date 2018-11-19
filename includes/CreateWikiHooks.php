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

	public static function onSetupAfterCache() {
		global $wgDBname, $wgConf, $wgCreateWikiPrivateWikis, $wgCreateWikiClosedWikis, $wgCreateWikiInactiveWikis, $wgLocalDatabases;

		// Check version differences in general caching, if not up to date
		// then let's make it up to date before we carry on.
		if ( !CreateWikiCDB::latest( $wgDBname ) ) {
			CreateWikiCDB::upsert( $wgDBname );
		}

		// If $wgConf isn't defined, let's define it.
		if ( !$wgConf ) {
			$wgConf = new SiteConfiguration;
		}

		$cdbSettings = [
			'sitename',
			'language',
			'private',
			'inactive',
			'closed',
			'settings'
		];

		$cdbOut = CreateWikiCDB::get( $wgDBname, $cdbSettings );

		$wgConf->settings['wgSitename'][$wgDBname] = $cdbOut['sitename'];
		$wgConf->settings['wgLanguageCode'][$wgDBname] = $cdbOut['language'];

		// Database lists handling
		$dbLists = [
			'all',
			'private',
			'closed',
			'inactive'
		];

		$cdbDB = CreateWikiCDB::getDatabaseList( $dbLists );
		$wgLocalDatabases = $cdbDB['all'];

		$wgConf->settings['wgCreateWikiIsPrivate'][$wgDBname] = (bool)$cdbOut['private'];
		$wgCreateWikiPrivateWikis = $cdbDB['private'];
		$wgConf->settings['wgCreateWikiIsInactive'][$wgDBname] = (bool)$cdbOut['inactive'];
		$wgCreateWikiInactiveWikis = $cdbDB['inactive'];
		$wgConf->settings['wgCreateWikiIsClosed'][$wgDBname] = (bool)$cdbOut['closed'];
		$wgCreateWikiClosedWikis = $cdbDB['closed'];

		// Extract the changes we've made
		$wgConf->extractAllGlobals( $wgDBname );

		// Hook so other extensions can use our $wgConf to build
		Hooks::run( 'CreateWikiCDBSetup', [ $wgConf, $wgDBname ] );

		// Hook for "final" execution after all other hooks.
		// Should be used for things that need to be done last
		// and shouldn't usign $wgConf at all!
		Hooks::run( 'CreateWikiCDBExecute', [ $wgDBname ] );
	}

	public static function onRegistration() {
		global $wgLogTypes;

		if ( !in_array( 'farmer', $wgLogTypes ) ) {
			$wgLogTypes[] = 'farmer';
		}
	}
}
