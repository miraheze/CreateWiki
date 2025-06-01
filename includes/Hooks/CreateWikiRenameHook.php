<?php

namespace Miraheze\CreateWiki\Hooks;

use Wikimedia\Rdbms\DBConnRef;

interface CreateWikiRenameHook {

	/**
	 * @param DBConnRef $cwdb
	 * @param string $oldDbName
	 * @param string $newDbName
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiRename(
		DBConnRef $cwdb,
		string $oldDbName,
		string $newDbName
	): void;
}
