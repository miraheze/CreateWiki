<?php

namespace Miraheze\CreateWiki\Hooks;

use Wikimedia\Rdbms\DBConnRef;

interface CreateWikiDeletionHook {

	/**
	 * @param DBConnRef $cwdb
	 * @param string $dbname
	 * @return void
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiDeletion(
		DBConnRef $cwdb,
		string $dbname
	): void;
}
