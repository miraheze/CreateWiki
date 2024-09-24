<?php

namespace Miraheze\CreateWiki\Hooks;

use Wikimedia\Rdbms\DBConnRef;

interface CreateWikiPhpBuilderHook {

	/**
	 * @param string $wiki
	 * @param DBConnRef $dbr
	 * @param array &$cacheArray
	 * @return void
	 */
	public function onCreateWikiPhpBuilder( $wiki, $dbr, &$cacheArray ): void;
}
