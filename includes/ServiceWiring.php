<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\CreateWikiNotificationsManager;
use Miraheze\CreateWiki\CreateWikiPhpDataFactory;
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
	'CreateWikiPhpDataFactory' => static function ( MediaWikiServices $services ): CreateWikiPhpDataFactory {
		return new CreateWikiPhpDataFactory(
			$services->getConnectionProvider(),
			$services->getObjectCacheFactory(),
			$services->get( 'CreateWikiHookRunner' ),
			new ServiceOptions(
				CreateWikiPhpDataFactory::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'CreateWiki' )
			),
		);
	},
	'CreateWikiHookRunner' => static function ( MediaWikiServices $services ): CreateWikiHookRunner {
		return new CreateWikiHookRunner( $services->getHookContainer() );
	},
];
