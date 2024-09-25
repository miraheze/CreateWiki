<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiGenerateDatabaseLists {

	/**
	 * @param array &$databaseLists
	 * @return void
	 */
	public function onCreateWikiGenerateDatabaseLists( &$databaseLists ): void;
}
