<?php

declare( strict_types = 1 );

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiGenerateDatabaseListsHook {

	/**
	 * @param array &$databaseLists
	 *   The database lists array that can be manipulated to
	 *   add or change the global database lists.
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiGenerateDatabaseLists( array &$databaseLists ): void;
}
