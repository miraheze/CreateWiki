<?php

namespace Miraheze\CreateWiki\Hooks;

use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Settings\SettingsBuilder;
use Miraheze\CreateWiki\ConfigNames;
use Profiler;
use ReflectionClass;
use Wikimedia\AtEase\AtEase;

final class ExtensionCallback {

	public static function onRegistrationCallback(
		array $extInfo,
		SettingsBuilder $settings
	): void {
		$config = $settings->getConfig();

		// We need these to start services to prevent errors/warnings
		Profiler::init( $config->get( MainConfigNames::Profiler ) );
		$settings->overrideConfigValue( MainConfigNames::TmpDirectory, wfTempDir() );

		// Temporarily enable global service instance
		$originalGlobalInstanceAllowed = self::setGlobalInstanceAllowed( true );

		$services = new MediaWikiServices( $settings->getConfig() );
		$wiringFiles = $config->get( MainConfigNames::ServiceWiringFiles );
		$services->loadWiringFiles( $wiringFiles );

		$dbname = $config->get( MainConfigNames::DBname );
		$isPrivate = false;

		$dataFactory = $services->getService( 'CreateWikiDataFactory' );
		$data = $dataFactory->newInstance( $dbname );
		$data->syncCache( $settings );

		if ( $config->get( ConfigNames::UsePrivateWikis ) ) {
			$cacheDir = $config->get( ConfigNames::CacheDirectory );
			$cachePath = "$cacheDir/$dbname.php";

			$cacheArray = AtEase::quietCall(
				static fn ( string $path ): mixed => include $path,
				$cachePath
			);

			if ( $cacheArray !== false ) {
				$isPrivate = (bool)( $cacheArray['states']['private'] ?? false );
			} else {
				$remoteWikiFactory = $services->getService( 'RemoteWikiFactory' );
				$isPrivate = $remoteWikiFactory->newInstance( $dbname )->isPrivate();
			}
		}

		// Apply read restrictions based on privacy
		$groupPermissions = $config->get( MainConfigNames::GroupPermissions );
		if ( $isPrivate ) {
			$groupPermissions['*']['read'] = false;
			$groupPermissions['sysop']['read'] = true;
		} else {
			$groupPermissions['*']['read'] = true;
		}

		$settings->overrideConfigValue( MainConfigNames::GroupPermissions, $groupPermissions );

		// Restore static state to prevent side effects
		self::setGlobalInstanceAllowed( $originalGlobalInstanceAllowed );
	}

	/**
	 * Set MediaWikiServices::$globalInstanceAllowed via reflection.
	 * Returns the original value before change.
	 */
	private static function setGlobalInstanceAllowed( bool $value ): bool {
		$refClass = new ReflectionClass( MediaWikiServices::class );
		$refProp = $refClass->getProperty( 'globalInstanceAllowed' );
		$refProp->setAccessible( true );
		$original = $refProp->getValue();
		$refProp->setValue( null, $value );
		return $original;
	}
}
