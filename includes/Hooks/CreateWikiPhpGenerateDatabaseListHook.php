<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiPhpGenerateDatabaseListHook {

	/**
	 * @param array &$databaseLists
	 * @return void
	 */
	public function onCreateWikiPhpGenerateDatabaseList( &$databaseLists ): void;
}
