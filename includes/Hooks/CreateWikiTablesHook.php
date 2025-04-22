<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiTablesHook {

	/**
	 * @param array &$tables
	 * @return void
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiTables( array &$tables ): void;
}
