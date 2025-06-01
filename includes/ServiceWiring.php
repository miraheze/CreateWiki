<?php

namespace Miraheze\CreateWiki;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\Helpers\RemoteWiki;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\CreateWikiDataFactory;
use Miraheze\CreateWiki\Services\CreateWikiNotificationsManager;
use Miraheze\CreateWiki\Services\CreateWikiRestUtils;
use Miraheze\CreateWiki\Services\CreateWikiValidator;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Miraheze\CreateWiki\Services\WikiRequestViewer;
use Psr\Log\LoggerInterface;

// PHPUnit does not understand coverage for this file.
// It is covered though, see ServiceWiringTest.
// @codeCoverageIgnoreStart

return [
	'CreateWikiConfig' => static function ( MediaWikiServices $services ): Config {
		return $services->getConfigFactory()->makeConfig( 'CreateWiki' );
	},
	'CreateWikiDatabaseUtils' => static function ( MediaWikiServices $services ): CreateWikiDatabaseUtils {
		return new CreateWikiDatabaseUtils( $services->getConnectionProvider() );
	},
	'CreateWikiDataFactory' => static function ( MediaWikiServices $services ): CreateWikiDataFactory {
		return new CreateWikiDataFactory(
			$services->getObjectCacheFactory(),
			$services->get( 'CreateWikiDatabaseUtils' ),
			$services->get( 'CreateWikiHookRunner' ),
			new ServiceOptions(
				CreateWikiDataFactory::CONSTRUCTOR_OPTIONS,
				$services->get( 'CreateWikiConfig' )
			)
		);
	},
	'CreateWikiHookRunner' => static function ( MediaWikiServices $services ): CreateWikiHookRunner {
		return new CreateWikiHookRunner( $services->getHookContainer() );
	},
	'CreateWikiLogger' => static function (): LoggerInterface {
		return LoggerFactory::getInstance( 'CreateWiki' );
	},
	'CreateWikiNotificationsManager' => static function (
		MediaWikiServices $services
	): CreateWikiNotificationsManager {
		return new CreateWikiNotificationsManager(
			$services->get( 'CreateWikiDatabaseUtils' ),
			RequestContext::getMain(),
			new ServiceOptions(
				CreateWikiNotificationsManager::CONSTRUCTOR_OPTIONS,
				$services->get( 'CreateWikiConfig' )
			),
			$services->getUserFactory()
		);
	},
	'CreateWikiRestUtils' => static function ( MediaWikiServices $services ): CreateWikiRestUtils {
		return new CreateWikiRestUtils(
			$services->get( 'CreateWikiDatabaseUtils' ),
			new ServiceOptions(
				CreateWikiRestUtils::CONSTRUCTOR_OPTIONS,
				$services->get( 'CreateWikiConfig' )
			)
		);
	},
	'CreateWikiValidator' => static function ( MediaWikiServices $services ): CreateWikiValidator {
		return new CreateWikiValidator(
			RequestContext::getMain(),
			new ServiceOptions(
				CreateWikiValidator::CONSTRUCTOR_OPTIONS,
				$services->get( 'CreateWikiConfig' )
			)
		);
	},
	'RemoteWikiFactory' => static function ( MediaWikiServices $services ): RemoteWikiFactory {
		return new RemoteWikiFactory(
			$services->get( 'CreateWikiDatabaseUtils' ),
			$services->get( 'CreateWikiDataFactory' ),
			$services->get( 'CreateWikiHookRunner' ),
			$services->getJobQueueGroupFactory(),
			new ServiceOptions(
				RemoteWiki::CONSTRUCTOR_OPTIONS,
				$services->get( 'CreateWikiConfig' )
			)
		);
	},
	'WikiManagerFactory' => static function ( MediaWikiServices $services ): WikiManagerFactory {
		return new WikiManagerFactory(
			$services->get( 'CreateWikiDatabaseUtils' ),
			$services->get( 'CreateWikiDataFactory' ),
			$services->get( 'CreateWikiHookRunner' ),
			$services->get( 'CreateWikiNotificationsManager' ),
			$services->get( 'CreateWikiValidator' ),
			$services->getExtensionRegistry(),
			$services->getUserFactory(),
			RequestContext::getMain(),
			new ServiceOptions(
				WikiManagerFactory::CONSTRUCTOR_OPTIONS,
				$services->get( 'CreateWikiConfig' )
			)
		);
	},
	'WikiRequestManager' => static function ( MediaWikiServices $services ): WikiRequestManager {
		return new WikiRequestManager(
			$services->get( 'CreateWikiDatabaseUtils' ),
			$services->get( 'CreateWikiNotificationsManager' ),
			$services->get( 'CreateWikiValidator' ),
			$services->getJobQueueGroupFactory(),
			$services->getLinkRenderer(),
			$services->getPermissionManager(),
			$services->getUserFactory(),
			$services->get( 'WikiManagerFactory' ),
			new ServiceOptions(
				WikiRequestManager::CONSTRUCTOR_OPTIONS,
				$services->get( 'CreateWikiConfig' )
			)
		);
	},
	'WikiRequestViewer' => static function ( MediaWikiServices $services ): WikiRequestViewer {
		return new WikiRequestViewer(
			RequestContext::getMain(),
			$services->get( 'CreateWikiHookRunner' ),
			$services->get( 'CreateWikiValidator' ),
			$services->getLanguageNameUtils(),
			$services->getPermissionManager(),
			$services->get( 'WikiRequestManager' ),
			new ServiceOptions(
				WikiRequestViewer::CONSTRUCTOR_OPTIONS,
				$services->get( 'CreateWikiConfig' )
			)
		);
	},
];

// @codeCoverageIgnoreEnd
