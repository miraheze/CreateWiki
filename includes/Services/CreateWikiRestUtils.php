<?php

namespace Miraheze\CreateWiki\Services;

use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\ConfigNames;
use Wikimedia\Message\MessageValue;

class CreateWikiRestUtils {

	private Config $config;
	private CreateWikiDatabaseUtils $databaseUtils;

	public function __construct(
		ConfigFactory $configFactory,
		CreateWikiDatabaseUtils $databaseUtils
	) {
		$this->config = $configFactory->makeConfig( 'CreateWiki' );
		$this->databaseUtils = $databaseUtils;
	}

	/**
	 * Called from the REST handlers.
	 *
	 * Checks that the current wiki is the global wiki and
	 * that the REST API is not disabled.
	 */
	public function checkEnv(): void {
		if ( !WikiMap::isCurrentWikiDbDomain( $this->databaseUtils->getGlobalWikiID() ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'createwiki-wikinotglobalwiki' ), 403
			);
		}

		if ( !$this->config->get( ConfigNames::EnableRESTAPI ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'createwiki-rest-disabled' ), 403
			);
		}
	}
}
