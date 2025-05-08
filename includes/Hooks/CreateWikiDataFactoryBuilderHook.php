<?php

namespace Miraheze\CreateWiki\Hooks;

use Wikimedia\Rdbms\IReadableDatabase;

interface CreateWikiDataFactoryBuilderHook {

	/**
	 * @param string $dbname
	 * @param IReadableDatabase $dbr
	 * @param array &$cacheArray
	 * @return void
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiDataFactoryBuilder(
		string $dbname,
		IReadableDatabase $dbr,
		array &$cacheArray
	): void;
}
