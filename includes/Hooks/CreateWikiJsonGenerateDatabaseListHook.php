<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiJsonGenerateDatabaseListHook {
	/**
	 * @param array &$databaseLists
	 * @return void
	 */
	public function onCreateWikiJsonGenerateDatabaseList( &$databaseLists ): void;
}
