<?php

namespace Miraheze\CreateWiki;

use Config;
use EchoAttributeManager;
use MediaWiki\Hook\SetupAfterCacheHook;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Notifications\EchoCreateWikiPresentationModel;
use Miraheze\CreateWiki\Notifications\EchoRequestCommentPresentationModel;
use Miraheze\CreateWiki\Notifications\EchoRequestDeclinedPresentationModel;

class Hooks implements
	SetupAfterCacheHook
{
	/** @var Config */
	private $config;

	/** @var CreateWikiHookRunner */
	private $hookRunner;

	/**
	 * @param Config $config
	 * @param CreateWikiHookRunner $hookRunner
	 */
	public function __construct( Config $config, CreateWikiHookRunner $hookRunner ) {
		$this->config = $config;
		$this->hookRunner = $hookRunner;
	}

	public static function onRegistration() {
		global $wgLogTypes;

		if ( !in_array( 'farmer', $wgLogTypes ) ) {
			$wgLogTypes[] = 'farmer';
		}
	}

	/** @inheritDoc */
	public function onSetupAfterCache() {
		global $wgGroupPermissions;

		$cacheDir = $this->config->get( 'CreateWikiCacheDirectory' );
		$dbName = $this->config->get( 'DBname' );

		$cWJ = new CreateWikiJson( $dbName, $this->hookRunner );
		$cWJ->update();

		if ( file_exists( $cacheDir . '/' . $dbName . '.json' ) ) {
			$cacheArray = json_decode( file_get_contents( $cacheDir . '/' . $dbName . '.json' ), true );
			$isPrivate = (bool)$cacheArray['states']['private'];
		} else {
			$remoteWiki = new RemoteWiki( $dbName, $this->hookRunner );
			$isPrivate = $remoteWiki->isPrivate();
		}

		// Safety Catch!
		if ( $isPrivate ) {
			$wgGroupPermissions['*']['read'] = false;
			$wgGroupPermissions['sysop']['read'] = true;
		} else {
			$wgGroupPermissions['*']['read'] = true;
		}
	}

	/**
	* Add CreateWiki events to Echo
	*
	* @param array &$notifications array of Echo notifications
	* @param array &$notificationCategories array of Echo notification categories
	* @param array &$icons array of icon details
	*/
	public static function onBeforeCreateEchoEvent(
		&$notifications, &$notificationCategories, &$icons
	) {
		$notificationCategories['wiki-creation'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-wiki-creation',
		];

		$notificationCategories['request-declined'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-wiki-request-declined'
		];

		$notificationCategories['request-comment'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-wiki-request-comment'
		];

		$notifications['wiki-creation'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'wiki-creation',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoCreateWikiPresentationModel::class,
			'immediate' => true
		];

		$notifications['request-declined'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'request-declined',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestDeclinedPresentationModel::class,
			'immediate' => true
		];

		$notifications['request-comment'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'request-comment',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestCommentPresentationModel::class,
			'immediate' => true
		];

		$icons['request-declined'] = [
			'path' => 'CreateWiki/modules/icons/decline.svg'
		];
	}
}
