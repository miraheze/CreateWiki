<?php

namespace Miraheze\CreateWiki\Hooks;

use Wikimedia\Rdbms\DBConnRef;

interface CreateWikiDeletionHook {

	/**
	 * @param DBConnRef $cwdb
	 * @param string $dbname
	 * @return void
	 */
	public function onCreateWikiDeletion(
		DBConnRef $cwdb,
		string $dbname
	): void;
}
