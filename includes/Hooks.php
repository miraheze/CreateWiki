<?php

namespace Miraheze\CreateWiki;

use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Extension\Notifications\AttributeManager;
use MediaWiki\Extension\Notifications\UserLocator;
use MediaWiki\Hook\GetMagicVariableIDsHook;
use MediaWiki\Hook\LoginFormValidErrorMessagesHook;
use MediaWiki\Hook\ParserGetVariableValueSwitchHook;
use MediaWiki\Hook\SetupAfterCacheHook;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\Hook\MakeGlobalVariablesScriptHook;
use MediaWiki\Title\Title;
use Miraheze\CreateWiki\Notifications\EchoCreateWikiPresentationModel;
use Miraheze\CreateWiki\Notifications\EchoRequestCommentPresentationModel;
use Miraheze\CreateWiki\Notifications\EchoRequestDeclinedPresentationModel;
use Miraheze\CreateWiki\Notifications\EchoRequestMoreDetailsPresentationModel;
use Miraheze\CreateWiki\Services\CreateWikiDataFactory;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use Wikimedia\Rdbms\IConnectionProvider;

class Hooks implements
	GetMagicVariableIDsHook,
	LoginFormValidErrorMessagesHook,
	ParserGetVariableValueSwitchHook,
	MakeGlobalVariablesScriptHook,
	SetupAfterCacheHook
{

	private Config $config;
	private CreateWikiDataFactory $dataFactory;
	private RemoteWikiFactory $remoteWikiFactory;
	private IConnectionProvider $connectionProvider;

	/**
	 * @param ConfigFactory $configFactory
	 * @param IConnectionProvider $connectionProvider
	 * @param CreateWikiDataFactory $dataFactory
	 * @param RemoteWikiFactory $remoteWikiFactory
	 */
	public function __construct(
		ConfigFactory $configFactory,
		IConnectionProvider $connectionProvider,
		CreateWikiDataFactory $dataFactory,
		RemoteWikiFactory $remoteWikiFactory
	) {
		$this->connectionProvider = $connectionProvider;
		$this->dataFactory = $dataFactory;
		$this->remoteWikiFactory = $remoteWikiFactory;

		$this->config = $configFactory->makeConfig( 'CreateWiki' );
	}

	public static function onRegistration() {
		global $wgLogTypes;

		if ( !in_array( 'farmer', $wgLogTypes ) ) {
			$wgLogTypes[] = 'farmer';
		}
	}

	/** @inheritDoc */
	public function onLoginFormValidErrorMessages( array &$messages ) {
		$messages[] = 'requestwiki-notloggedin';
	}

	/** @inheritDoc */
	public function onSetupAfterCache() {
		global $wgGroupPermissions;

		$dbName = $this->config->get( MainConfigNames::DBname );
		$isPrivate = false;

		$data = $this->dataFactory->newInstance( $dbName );
		$data->syncCache();

		if ( $this->config->get( 'CreateWikiUsePrivateWikis' ) ) {
			$cacheDir = $this->config->get( 'CreateWikiCacheDirectory' );
			if ( file_exists( $cacheDir . '/' . $dbName . '.php' ) ) {
				$cacheArray = include $cacheDir . '/' . $dbName . '.php';
				$isPrivate = (bool)$cacheArray['states']['private'];
			} else {
				$wiki = $this->remoteWikiFactory->newInstance( $dbName );
				$isPrivate = $wiki->isPrivate();
			}
		}

		// Safety Catch!
		if ( $isPrivate ) {
			$wgGroupPermissions['*']['read'] = false;
			$wgGroupPermissions['sysop']['read'] = true;
		} else {
			$wgGroupPermissions['*']['read'] = true;
		}
	}

	/** @inheritDoc */
	public function onParserGetVariableValueSwitch(
		$parser,
		&$variableCache,
		$magicWordId,
		&$ret,
		$frame
	) {
		if ( $magicWordId === 'numberofwikirequests' ) {
			$dbr = $this->connectionProvider->getReplicaDatabase(
				$this->config->get( 'CreateWikiGlobalWiki' )
			);

			$ret = $variableCache[$magicWordId] = $dbr->selectRowCount( 'cw_requests', '*' );
		}
	}

	/** @inheritDoc */
	public function onMakeGlobalVariablesScript(
		&$vars,
		$out
	): void {
		if ( $out->getTitle()->isSubpageOf( Title::newFromText( 'Special:RequestWikiQueue' ) ) ) {
			$vars['CreateWikiCannedResponses'] = $this->config->get( 'CreateWikiCannedResponses' );
		}
	}

	/** @inheritDoc */
	public function onGetMagicVariableIDs( &$variableIDs ) {
		$variableIDs[] = 'numberofwikirequests';
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
			'no-dismiss' => [ 'all' ]
		];

		$notificationCategories['request-declined'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-wiki-request-declined',
			'no-dismiss' => [ 'email' ]
		];

		$notificationCategories['request-moredetails'] = [
			'priority' => 1,
			'no-dismiss' => [ 'all' ]
		];

		$notificationCategories['request-comment'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-wiki-request-comment'
		];

		$notifications['wiki-creation'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'wiki-creation',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoCreateWikiPresentationModel::class,
			'immediate' => true
		];

		$notifications['request-declined'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'request-declined',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestDeclinedPresentationModel::class,
			'immediate' => true
		];

		$notifications['request-moredetails'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'request-moredetails',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestMoreDetailsPresentationModel::class,
			'immediate' => true
		];

		$notifications['request-comment'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
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
