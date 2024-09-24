<?php

namespace Miraheze\CreateWiki\Hooks;

use Wikimedia\Rdbms\DBConnRef;

interface CreateWikiDeletionHook {

	/**
	 * @param DBConnRef $cwdb
	 * @param string $wiki dbname
	 * @return void
	 */
	public function onCreateWikiDeletion( $cwdb, $wiki ): void;
}
