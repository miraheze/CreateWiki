<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\CreateWikiDataFactory;
use Miraheze\CreateWiki\Services\CreateWikiNotificationsManager;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Miraheze\CreateWiki\Services\WikiRequestManager;

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
	'WikiManagerFactory' => static function ( MediaWikiServices $services ): WikiManagerFactory {
		return new WikiManagerFactory(
			$services->getConnectionProvider(),
			$services->get( 'CreateWikiDataFactory' ),
			$services->get( 'CreateWikiHookRunner' ),
			$services->get( 'CreateWiki.NotificationsManager' ),
			$services->getUserFactory(),
			RequestContext::getMain(),
			new ServiceOptions(
				WikiManagerFactory::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'CreateWiki' )
			)
		);
	},
	'WikiRequestManager' => static function ( MediaWikiServices $services ): WikiRequestManager {
		return new WikiRequestManager(
			$services->getConnectionProvider(),
			$services->get( 'CreateWiki.NotificationsManager' ),
			$services->getJobQueueGroupFactory(),
			$services->getLinkRenderer(),
			$services->ggetUserFactory(),
			$services->get( 'WikiManagerFactory' ),
			new ServiceOptions(
				WikiRequestManager::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'CreateWiki' )
			)
		);
	},
];
