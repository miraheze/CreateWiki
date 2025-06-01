<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiGenerateDatabaseListsHook {

	/**
	 * @param array &$databaseLists
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiGenerateDatabaseLists( array &$databaseLists ): void;
}
