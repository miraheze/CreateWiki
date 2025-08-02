<?php

namespace Miraheze\CreateWiki\Hooks;

use Wikimedia\Rdbms\IReadableDatabase;

interface CreateWikiDataFactoryBuilderHook {

	/**
	 * @param string $dbname
	 *   The target wiki's database name (e.g., "examplewiki").
	 * @param IReadableDatabase $dbr
	 *   Database (read) connection to use (connected to virtual-createwiki).
	 * @param array &$cacheArray
	 *   The cache array that can be manipulated to add new entries to the
	 *   CreateWiki cache for the individual wiki.
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiDataFactoryBuilder(
		string $dbname,
		IReadableDatabase $dbr,
		array &$cacheArray
	): void;
}
