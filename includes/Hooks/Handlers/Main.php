<?php

namespace Miraheze\CreateWiki\Hooks\Handlers;

use MediaWiki\Block\Hook\GetAllBlockActionsHook;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Hook\GetMagicVariableIDsHook;
use MediaWiki\Hook\LoginFormValidErrorMessagesHook;
use MediaWiki\Hook\ParserGetVariableValueSwitchHook;
use MediaWiki\Hook\SetupAfterCacheHook;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\Hook\MakeGlobalVariablesScriptHook;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\CreateWikiDataFactory;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use Wikimedia\Rdbms\IConnectionProvider;

class Main implements
	GetAllBlockActionsHook,
	GetMagicVariableIDsHook,
	LoginFormValidErrorMessagesHook,
	ParserGetVariableValueSwitchHook,
	MakeGlobalVariablesScriptHook,
	SetupAfterCacheHook,
	UserGetReservedNamesHook
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
	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = 'CreateWiki Extension';
	}

	/** @inheritDoc */
	public function onGetAllBlockActions( &$actions ) {
		if ( !WikiMap::isCurrentWikiId( $this->config->get( ConfigNames::GlobalWiki ) ) ) {
			return;
		}

		$actions[ 'requestwiki' ] = 150;
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
				$remoteWiki = $this->remoteWikiFactory->newInstance( $dbName );
				$isPrivate = $remoteWiki->isPrivate();
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

			$ret = $variableCache[$magicWordId] = $dbr->newSelectQueryBuilder()
				->select( '*' )
				->from( 'cw_requests' )
				->caller( __METHOD__ )
				->fetchRowCount();
		}

		if ( $magicWordId === 'wikicreationdate' ) {
			$remoteWiki = $this->remoteWikiFactory->newInstance(
				WikiMap::getCurrentWikiId()
			);

			$ret = $variableCache[$magicWordId] = $remoteWiki->getCreationDate();
		}
	}

	/** @inheritDoc */
	public function onMakeGlobalVariablesScript(
		&$vars,
		$out
	): void {
		if ( $out->getTitle()->isSubpageOf( SpecialPage::getTitleFor( 'RequestWikiQueue' ) ) ) {
			$vars[ConfigNames::CannedResponses] = $this->config->get( ConfigNames::CannedResponses );
		}
	}

	/** @inheritDoc */
	public function onGetMagicVariableIDs( &$variableIDs ) {
		$variableIDs[] = 'numberofwikirequests';
		$variableIDs[] = 'wikicreationdate';
	}
}
