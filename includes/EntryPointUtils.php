<?php
namespace Miraheze\CreateWiki;

use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;

class EntryPointUtils {

	public static function currentWikiIsGlobalWiki(): bool {
		$config = MediaWikiServices::getConfigFactory()->makeConfig( 'CreateWiki' );
		if ( $config->get( 'CreateWikiGlobalWiki' ) === WikiMap::getCurrentWikiId() ) {
			return true;
		} else {
			return false;
		}
	}
}
