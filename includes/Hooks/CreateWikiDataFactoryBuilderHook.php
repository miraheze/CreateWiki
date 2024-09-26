<?php

namespace Miraheze\CreateWiki\Hooks;

use Wikimedia\Rdbms\IReadableDatabase;

interface CreateWikiDataFactoryBuilderHook {

	/**
	 * @param string $wiki
	 * @param IReadableDatabase $dbr
	 * @param array &$cacheArray
	 * @return void
	 */
	public function onCreateWikiDataFactoryBuilder( $wiki, $dbr, &$cacheArray ): void;
}
