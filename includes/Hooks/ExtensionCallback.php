<?php

namespace Miraheze\CreateWiki\Hooks;

use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Settings\SettingsBuilder;
use Miraheze\CreateWiki\ConfigNames;
use Profiler;
use ReflectionClass;
use Wikimedia\AtEase\AtEase;

class ExtensionCallback {

	public static function onRegistrationCallback(
		array $extInfo,
		SettingsBuilder $settings
	): void {
		// Initialize what we need to start services here
		Profiler::init( $settings->getConfig()->get( MainConfigNames::Profiler ) );
		$settings->overrideConfigValue( MainConfigNames::TmpDirectory, wfTempDir() );

		$refClass = new ReflectionClass( MediaWikiServices::class );
		$refProp = $refClass->getProperty( 'globalInstanceAllowed' );
		$refProp->setAccessible( true );
		$originalValue = $refProp->getValue();

		$refProp->setValue( null, true );

		$services = new MediaWikiServices( $settings->getConfig() );
		$wiringFiles = $settings->getConfig()->get( MainConfigNames::ServiceWiringFiles );
		$services->loadWiringFiles( $wiringFiles );

		$dbname = $settings->getConfig()->get( MainConfigNames::DBname );
		$isPrivate = false;

		$dataFactory = $services->getService( 'CreateWikiDataFactory' );
		$data = $dataFactory->newInstance( $dbname );
		$data->syncCache();

		if ( $settings->getConfig()->get( ConfigNames::UsePrivateWikis ) ) {
			// Avoid using file_exists for performance reasons. Including the file directly leverages
			// the opcode cache and prevents any file system access.
			// We only handle failures if the include does not work.

			$cacheDir = $settings->getConfig()->get( ConfigNames::CacheDirectory );

			$cachePath = $cacheDir . '/' . $dbname . '.php';
			$cacheArray = AtEase::quietCall( static function ( $path ) {
				return include $path;
			}, $cachePath );

			if ( $cacheArray !== false ) {
				$isPrivate = (bool)$cacheArray['states']['private'];
			} else {
				$remoteWikiFactory = $services->getService( 'RemoteWikiFactory' );
				$remoteWiki = $remoteWikiFactory->newInstance( $dbname );
				$isPrivate = $remoteWiki->isPrivate();
			}
		}

		// Safety Catch!
		$groupPermissions = $settings->getConfig()->get( MainConfigNames::GroupPermissions );
		if ( $isPrivate ) {
			$groupPermissions['*']['read'] = false;
			$groupPermissions['sysop']['read'] = true;
		} else {
			$groupPermissions['*']['read'] = true;
		}

		$settings->overrideConfigValue( MainConfigNames::GroupPermissions, $groupPermissions );
		// Reset services so Setup.php can start them properly
		$refProp->setValue( null, $originalValue );
		// MediaWikiServices::resetGlobalInstance();
	}
}
