<?php

namespace Miraheze\CreateWiki\Hooks\Handlers;

use MediaWiki\Block\Hook\GetAllBlockActionsHook;
use MediaWiki\Config\Config;
use MediaWiki\Hook\GetMagicVariableIDsHook;
use MediaWiki\Hook\LoginFormValidErrorMessagesHook;
use MediaWiki\Hook\ParserGetVariableValueSwitchHook;
use MediaWiki\Hook\SetupAfterCacheHook;
use MediaWiki\Output\Hook\MakeGlobalVariablesScriptHook;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\CreateWikiDataFactory;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;

class Main implements
	GetAllBlockActionsHook,
	GetMagicVariableIDsHook,
	LoginFormValidErrorMessagesHook,
	ParserGetVariableValueSwitchHook,
	MakeGlobalVariablesScriptHook,
	SetupAfterCacheHook,
	UserGetReservedNamesHook
{

	public function __construct(
		private readonly Config $config,
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CreateWikiDataFactory $dataFactory,
		private readonly RemoteWikiFactory $remoteWikiFactory
	) {
	}

	/** @inheritDoc */
	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = 'CreateWiki AI';
		$reservedUsernames[] = 'CreateWiki Extension';
	}

	/** @inheritDoc */
	public function onGetAllBlockActions( &$actions ) {
		if ( !$this->databaseUtils->isCurrentWikiCentral() ) {
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
		$data = $this->dataFactory->newInstance();
		$data->syncCache();
	}

	/**
	 * @inheritDoc
	 * @param Parser $parser @phan-unused-param
	 * @param PPFrame $frame @phan-unused-param
	 */
	public function onParserGetVariableValueSwitch(
		$parser,
		&$variableCache,
		$magicWordId,
		&$ret,
		$frame
	) {
		if ( $magicWordId === 'numberofopenwikirequests' ) {
			$dbr = $this->databaseUtils->getCentralWikiReplicaDB();
			$ret = $variableCache[$magicWordId] = $dbr->newSelectQueryBuilder()
				->select( '*' )
				->from( 'cw_requests' )
				->where( [ 'cw_status' => 'inreview' ] )
				->caller( __METHOD__ )
				->fetchRowCount();
		}

		if ( $magicWordId === 'numberofwikirequests' ) {
			$dbr ??= $this->databaseUtils->getCentralWikiReplicaDB();
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
		if ( $out->getTitle()?->isSubpageOf( SpecialPage::getTitleFor( 'RequestWikiQueue' ) ) ) {
			$vars[ConfigNames::CannedResponses] = $this->config->get( ConfigNames::CannedResponses );
		}
	}

	/** @inheritDoc */
	public function onGetMagicVariableIDs( &$variableIDs ) {
		$variableIDs[] = 'numberofopenwikirequests';
		$variableIDs[] = 'numberofwikirequests';
		$variableIDs[] = 'wikicreationdate';
	}
}
