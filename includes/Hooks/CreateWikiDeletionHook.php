<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiDeletionHook {
	/**
	 * @param \Wikimedia\Rdbms\DBConnRef $cwdb
	 * @param string $wiki dbname
	 * @return void
	 */
	public function onCreateWikiDeletion( $cwdb, $wiki ): void;
}
