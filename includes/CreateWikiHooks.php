<?php

use MediaWiki\MediaWikiServices;

class CreateWikiHooks {
	public static function getConfig( string $var ) {
		return MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' )->get( $var );
	}

	public static function fnCreateWikiSchemaUpdates( DatabaseUpdater $updater ) {
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

		$updater->modifyExtensionField(
			'cw_comments',
			'cw_comment_user',
			__DIR__ . '/../sql/patches/patch-cw_comments-blob.sql'
		);

		$updater->addExtensionField(
			'cw_requests',
			'cw_bio',
			__DIR__ . '/../sql/patches/patch-cw_requests-add-cw_bio.sql'
		);

		$updater->addExtensionField(
			'cw_wikis',
			'wiki_inactive_exempt_reason',
			__DIR__ . '/../sql/patches/patch-cw_wikis-add-wiki_inactive_exempt_reason.sql'
		);

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

		$updater->addExtensionIndex(
			'cw_wikis',
			'wiki_dbname',
			__DIR__ . '/../sql/patches/patch-cw_wikis-add-indexes.sql'
		);
	}

	public static function onRegistration() {
		global $wgLogTypes;

		if ( !in_array( 'farmer', $wgLogTypes ) ) {
			$wgLogTypes[] = 'farmer';
		}
	}

	public static function onSetupAfterCache() {
		// phpcs:ignore MediaWiki.NamingConventions.ValidGlobalName.allowedPrefix
		global $wi, $wgConf, $wgGroupPermissions;

		$cWJ = new CreateWikiJson( self::getConfig( 'DBname' ) );

		$cWJ->update();

		$wi->readCache();

		// Redefine
		$wgConf = $wi->config;

		// Unfortunately we don't exist in a world where no one sets
		// any defaults - so we have to override our version over exts.
		$wgConf->extractAllGlobals( self::getConfig( 'DBname' ) );

		// Safety Catch!
		if ( $wgConf->settings['cwPrivate'][self::getConfig( 'DBname' )] ) {
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

		$notificationCategories['request-comment'] = [
			'priority' => 2,
			'tooltip' => 'echo-pref-tooltip-wiki-comment'
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

		$notifications['request-comment'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'farmer',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestCommentPresentationModel::class,
			'immediate' => true
		];

		return true;
	}

}
