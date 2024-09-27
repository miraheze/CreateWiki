<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\CreateWikiDataFactory;
use Miraheze\CreateWiki\Services\CreateWikiNotificationsManager;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;

return [
	'CreateWiki.NotificationsManager' => static function (
		MediaWikiServices $services
	): CreateWikiNotificationsManager {
		return new CreateWikiNotificationsManager(
			$services->getConnectionProvider(),
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
	'RemoteWikiFactory' => static function ( MediaWikiServices $services ): RemoteWikiFactory {
		return new RemoteWikiFactory(
			$services->getConnectionProvider(),
			$services->get( 'CreateWikiDataFactory' ),
			$services->get( 'CreateWikiHookRunner' ),
			$services->getJobQueueGroupFactory(),
			new ServiceOptions(
				RemoteWikiFactory::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'CreateWiki' )
			)
		);
	},
];
