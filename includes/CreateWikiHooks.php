<?php
class CreateWikiHooks {
	public static function fnCreateWikiSchemaUpdates( DatabaseUpdater $updater ) {
		global $wgCreateWikiDatabase, $wgCreateWikiGlobalWiki, $wgDBname;

		if ( $wgCreateWikiGlobalWiki === $wgDBname ) {
			$updater->addExtensionTable(
				'cw_requests',
				__DIR__ . '/../sql/cw_requests.sql'
			);

			$updater->addExtensionTable(
				'cw_comments',
				__DIR__ . '/../sql/cw_comments.sql'
			);

			$updater->modifyField(
 				'cw_comments',
 				'cw_comment_user',
 				__DIR__ . '/../sql/patches/patch-cw_comments-int.sql',
				true
 			);

			$updater->modifyField(
 				'cw_requests',
 				'cw_user',
 				__DIR__ . '/../sql/patches/patch-cw_requests-int.sql',
				true
 			);
		}

		if ( $wgCreateWikiDatabase === $wgDBname ) {
			$updater->addExtensionTable(
				'cw_wikis',
				__DIR__ . '/../sql/cw_wikis.sql'
			);

			$updater->addExtensionField(
				'cw_wikis',
				'wiki_deleted',
				__DIR__ . '/../sql/patches/patch-deleted-wiki.sql'
			);

			$updater->addExtensionField(
				'cw_wikis',
				'wiki_deleted_timestamp',
				__DIR__ . '/../sql/patches/patch-deleted-wiki.sql'
			);
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
