<?php

namespace Miraheze\CreateWiki\Hooks;

use Wikimedia\Rdbms\DBConnRef;

interface CreateWikiRenameHook {

	/**
	 * @param DBConnRef $cwdb
	 *   Database (write) connection to use (connected to virtual-createwiki).
	 * @param string $oldDbName
	 *   The name of the old database that is being renamed from.
	 * @param string $newDbName
	 *   The name of the new database that is being renamed to.
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiRename(
		DBConnRef $cwdb,
		string $oldDbName,
		string $newDbName
	): void;
}
