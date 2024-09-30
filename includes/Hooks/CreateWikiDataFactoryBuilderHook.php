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
	public function onCreateWikiDataFactoryBuilder(
		string $wiki,
		IReadableDatabase $dbr,
		array &$cacheArray
	): void;
}
