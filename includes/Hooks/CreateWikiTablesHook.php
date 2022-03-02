<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiTablesHook {
	/**
	 * @param array $cwdb
	 * @return void
	 */
	public function onCreateWikiTables( $cTables ): void;
}
