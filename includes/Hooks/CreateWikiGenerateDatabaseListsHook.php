<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiGenerateDatabaseListsHook {

	/**
	 * @param array &$databaseLists
	 * @return void
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiGenerateDatabaseLists( array &$databaseLists ): void;
}
