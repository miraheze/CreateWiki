<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiGenerateDatabaseListsHook {

	/**
	 * @param array &$databaseLists
	 * @return void
	 */
	public function onCreateWikiGenerateDatabaseLists( array &$databaseLists ): void;
}
