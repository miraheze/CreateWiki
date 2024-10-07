<?php

namespace Miraheze\CreateWiki\Hooks;

use Wikimedia\Rdbms\DBConnRef;

interface CreateWikiRenameHook {

	/**
	 * @param DBConnRef $cwdb
	 * @param string $oldDbName
	 * @param string $newDbName
	 * @return void
	 */
	public function onCreateWikiRename(
		DBConnRef $cwdb,
		string $oldDbName,
		string $newDbName
	): void;
}
