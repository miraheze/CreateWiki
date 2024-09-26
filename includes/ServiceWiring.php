<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\CreateWikiDataFactory;
use Miraheze\CreateWiki\CreateWikiNotificationsManager;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;

return [
	'CreateWiki.NotificationsManager' => static function ( MediaWikiServices $services ): CreateWikiNotificationsManager {
		return new CreateWikiNotificationsManager(
			$services->getDBLoadBalancerFactory(),
			RequestContext::getMain(),
			new ServiceOptions(
				CreateWikiNotificationsManager::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'CreateWiki' )
			),
			$services->getUserFactory()
		);
	},
	'CreateWikiDataFactory' => static function ( MediaWikiServices $services ): CreateWikiDataFactory {
		return new CreateWikiDataFactory(
			$services->getConnectionProvider(),
			$services->getObjectCacheFactory(),
			$services->get( 'CreateWikiHookRunner' ),
			new ServiceOptions(
				CreateWikiDataFactory::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'CreateWiki' )
			)
		);
	},
	'CreateWikiHookRunner' => static function ( MediaWikiServices $services ): CreateWikiHookRunner {
		return new CreateWikiHookRunner( $services->getHookContainer() );
	},
];
