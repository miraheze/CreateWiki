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

		return true;
	}

	public static function onRegistration() {
		global $wgLogTypes;

		if ( !in_array( 'farmer', $wgLogTypes ) ) {
			$wgLogTypes[] = 'farmer';
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

		return true;
	}

}
