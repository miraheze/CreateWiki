<?php

namespace Miraheze\CreateWiki;

use MediaWiki\Config\Config;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Message\MessageValue;

class RestUtils {

	/**
	 * Called from the REST handlers, checks that the current wiki is the global wiki and that the REST API is not disabled
	 */
	public static function checkEnv( Config $config ): void {
		if ( !WikiMap::isCurrentWikiId( $config->get( 'CreateWikiGlobalWiki' ) ) ) {
			throw new LocalizedHttpException( new MessageValue( 'createwiki-wikinotglobalwiki' ), 403 );
		}

		if ( $config->get( 'CreateWikiDisableRESTAPI' ) ) {
			throw new LocalizedHttpException( new MessageValue( 'createwiki-rest-disabled' ), 403 );
		}
	}
}
