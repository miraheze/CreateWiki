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

		$updater->addExtensionField(
			'cw_wikis',
			'wiki_experimental',
			__DIR__ . '/../sql/patches/patch-cw_wikis-add-wiki_experimental.sql'
		);
	}

	public static function onRegistration() {
		global $wgLogTypes;

		if ( !in_array( 'farmer', $wgLogTypes ) ) {
			$wgLogTypes[] = 'farmer';
		}
	}

	public static function onSetupAfterCache() {
		global $wgGroupPermissions;

		$cacheDir = self::getConfig( 'CreateWikiCacheDirectory' );
		$dbName = self::getConfig( 'DBname' );

		$cWJ = new CreateWikiJson( $dbName );
		$cWJ->update();

		if ( file_exists( $cacheDir . '/' . $dbName . '.json' ) ) {
			$cacheArray = json_decode( file_get_contents( $cacheDir . '/' . $dbName . '.json' ), true );
			$isPrivate = (bool)$cacheArray['states']['private'];
		} else {
			$remoteWiki = new RemoteWiki( $dbName );
			$isPrivate = $remoteWiki->isPrivate();
		}

		// Safety Catch!
		if ( $isPrivate ) {
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
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-wiki-creation',
		];

		$notificationCategories['wiki-rename'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-wiki-rename'
		];

		$notificationCategories['request-declined'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-wiki-request-declined'
		];

		$notificationCategories['request-comment'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-wiki-request-comment'
		];

		$notifications['wiki-creation'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'wiki-creation',
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
			'category' => 'wiki-rename',
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
			'category' => 'request-declined',
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
			'category' => 'request-comment',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestCommentPresentationModel::class,
			'immediate' => true
		];

		$icons['request-declined'] = [
			'path' => 'CreateWiki/modules/icons/decline.svg'
		];
	}
}
