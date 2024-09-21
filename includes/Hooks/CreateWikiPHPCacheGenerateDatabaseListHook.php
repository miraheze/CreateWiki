<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiPHPCacheGenerateDatabaseListHook {
	/**
	 * @param array &$databaseLists
	 * @return void
	 */
	public function onCreateWikiPHPCacheGenerateDatabaseList( &$databaseLists ): void;
}
