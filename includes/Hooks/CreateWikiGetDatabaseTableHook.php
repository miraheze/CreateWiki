<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiGetDatabaseTableHook {
	/**
	 * @param string &$table
	 * @return void
	 */
	public function onCreateWikiGetDatabaseTable( &$table ): void;
}
