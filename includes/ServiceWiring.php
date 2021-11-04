<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;

return [
	'CreateWiki.NotificationsManager' => static function ( MediaWikiServices $services ): CreateWikiNotificationsManager {
		return new CreateWikiNotificationsManager(
			RequestContext::getMain(),
			new ServiceOptions(
				CreateWikiNotificationsManager::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'createwiki' )
			),
			$services->getUserFactory()
		);
	},
];
