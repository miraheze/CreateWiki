<?php

namespace Miraheze\CreateWiki\Hooks;

use Wikimedia\Rdbms\DBConnRef;

interface CreateWikiPHPCacheBuilderHook {
	/**
	 * @param string $wiki
	 * @param DBConnRef $dbr
	 * @param array &$cacheArray
	 * @return void
	 */
	public function onCreateWikiPHPCacheBuilder( $wiki, $dbr, &$cacheArray ): void;
}
