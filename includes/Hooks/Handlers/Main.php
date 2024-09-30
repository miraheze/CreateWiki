<?php

namespace Miraheze\CreateWiki\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Hook\GetMagicVariableIDsHook;
use MediaWiki\Hook\LoginFormValidErrorMessagesHook;
use MediaWiki\Hook\ParserGetVariableValueSwitchHook;
use MediaWiki\Hook\SetupAfterCacheHook;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\Hook\MakeGlobalVariablesScriptHook;
use MediaWiki\Title\Title;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\CreateWikiDataFactory;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use Wikimedia\Rdbms\IConnectionProvider;

class Main implements
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

		if ( $this->config->get( ConfigNames::UsePrivateWikis ) ) {
			$cacheDir = $this->config->get( ConfigNames::CacheDirectory );
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
				$this->config->get( ConfigNames::GlobalWiki )
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
			$vars['CreateWikiCannedResponses'] = $this->config->get( ConfigNames::CannedResponses );
		}
	}

	/** @inheritDoc */
	public function onGetMagicVariableIDs( &$variableIDs ) {
		$variableIDs[] = 'numberofwikirequests';
	}
}
