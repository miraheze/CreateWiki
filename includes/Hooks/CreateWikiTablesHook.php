<?php

declare( strict_types = 1 );

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiTablesHook {

	/**
	 * @param array &$tables
	 *   The array of tables that can be manipulated to modify or remove
	 *   wiki field data when renaming or deleting wikis.
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiTables( array &$tables ): void;
}
