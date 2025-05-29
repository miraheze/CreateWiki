<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiTablesHook {

	/**
	 * @param array &$tables
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiTables( array &$tables ): void;
}
