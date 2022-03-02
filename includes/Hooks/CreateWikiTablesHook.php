<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiTablesHook {
	/**
	 * @param array $cTables
	 * @return void
	 */
	public function onCreateWikiTables( $cTables ): void;
}
