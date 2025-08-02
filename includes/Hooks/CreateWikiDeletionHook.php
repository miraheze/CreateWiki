<?php

namespace Miraheze\CreateWiki\Hooks;

use Wikimedia\Rdbms\DBConnRef;

interface CreateWikiDeletionHook {

	/**
	 * @param DBConnRef $cwdb
	 *   Database (write) connection to use (connected to virtual-createwiki).
	 * @param string $dbname
	 *   The target wiki's database name (e.g., "examplewiki").
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiDeletion(
		DBConnRef $cwdb,
		string $dbname
	): void;
}
