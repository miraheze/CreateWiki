<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\CreateWikiNotificationsManager;

return [
	'CreateWiki.NotificationsManager' => static function ( MediaWikiServices $services ): CreateWikiNotificationsManager {
		return new CreateWikiNotificationsManager(
			$services->getDBLoadBalancerFactory(),
			RequestContext::getMain(),
			new ServiceOptions(
				CreateWikiNotificationsManager::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'createwiki' )
			),
			$services->getUserFactory()
		);
	},
];
