<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiTablesHook {

	/**
	 * @param array &$cTables
	 * @return void
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiTables( array &$cTables ): void;
}
