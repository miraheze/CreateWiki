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

			$updater->modifyExtensionField(
 				'cw_comments',
 				'cw_comment_user',
 				__DIR__ . '/../sql/patches/patch-cw_comments-int.sql'
 			);

			$updater->modifyExtensionField(
 				'cw_requests',
 				'cw_user',
 				__DIR__ . '/../sql/patches/patch-cw_requests-int.sql'
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

			$updater->addExtensionField(
				'cw_wikis',
				'wiki_inactive_exempt',
				__DIR__ . '/../sql/patches/patch-inactive-exempt.sql'
			);

			$updater->addExtensionField(
				'cw_wikis',
				'wiki_locked',
				__DIR__ . '/../sql/patches/patch-locked-wiki.sql'
			);

			$updater->addExtensionField(
				'cw_wikis',
				'wiki_url',
				__DIR__ . '/../sql/patches/patch-domain-cols.sql'
			);

			$updater->modifyExtensionTable(
				'cw_wikis',
 				__DIR__ . '/../sql/patches/patch-cw_wikis-add-indexes.sql'
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

	public static function onSetupAfterCache() {
		global $wgDBname, $wi, $wgConf, $wgGroupPermissions;

		$cWJ = new CreateWikiJson( $wgDBname );

		$cWJ->update();

		$wi->readCache();

		// Redefine
		$wgConf = $wi->config;

		// Unfortunately we don't exist in a world where no one sets
		// any defaults - so we have to override our version over exts.
		$wgConf->extractAllGlobals( $wgDBname );

		// Safety Catch!
		if ( $wgConf->settings['cwPrivate'][$wgDBname] ) {
			$wgGroupPermissions['*']['read'] = false;
			$wgGroupPermissions['sysop']['read'] = true;
		} else {
			$wgGroupPermissions['*']['read'] = true;
		}
	}

	/**
	* Add CreateWiki events to Echo
	*
	* @param array &$notifications array of Echo notifications
	* @param array &$notificationCategories array of Echo notification categories
	* @param array &$icons array of icon details
	* @return bool
	*/
	public static function onBeforeCreateEchoEvent(
		&$notifications, &$notificationCategories, &$icons
	) {
		$notificationCategories['wiki-creation'] = [
			'priority' => 2,
			'tooltip' => 'echo-pref-tooltip-wiki-creation',
		];

		$notificationCategories['wiki-rename'] = [
			'priority' => 2,
			'tooltip' => 'echo-pref-tooltip-wiki-rename'
		];

		$notificationCategories['request-declined'] = [
			'priority' => 2,
			'tooltip' => 'echo-pref-tooltip-wiki-request-declined'
		];

		$notifications['wiki-creation'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'farmer',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoCreateWikiPresentationModel::class,
			'immediate' => true
		];

		$notifications['wiki-rename'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'farmer',
			'group' => 'postive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRenameWikiPresentationModel::class,
			'immediate' => true
		];

		$notifications['request-declined'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'farmer',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestDeclinedPresentationModel::class,
			'immediate' => true
		];

		return true;
	}

}
