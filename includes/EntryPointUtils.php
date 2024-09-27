<?php

namespace Miraheze\CreateWiki;

use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;

// TODO: remove this class and use service injection
// to get config in special pages directly
class EntryPointUtils {

	public static function currentWikiIsGlobalWiki(): bool {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );
		if ( WikiMap::isCurrentWikiId( $config->get( 'CreateWikiGlobalWiki' ) ) ) {
			return true;
		} else {
			return false;
		}
	}
}
