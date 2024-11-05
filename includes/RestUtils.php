<?php

namespace Miraheze\CreateWiki;

use MediaWiki\Config\Config;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Message\MessageValue;
use Wikimedia\Rdbms\IConnectionProvider;

class RestUtils {

	/**
	 * Called from the REST handlers.
	 *
	 * Checks that the current wiki is the global wiki and
	 * that the REST API is not disabled.
	 */
	public static function checkEnv(
		Config $config,
		IConnectionProvider $connectionProvider
	): void {
		$dbr = $connectionProvider->getReplicaDatabase( 'virtual-createwiki-global' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			throw new LocalizedHttpException( new MessageValue( 'createwiki-wikinotglobalwiki' ), 403 );
		}

		if ( !$config->get( ConfigNames::EnableRESTAPI ) ) {
			throw new LocalizedHttpException( new MessageValue( 'createwiki-rest-disabled' ), 403 );
		}
	}
}
