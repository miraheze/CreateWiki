<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiRenameHook {
	/**
	 * @param \Wikimedia\Rdbms\DBConnRef $cwdb
	 * @param string $wiki dbname
	 * @return void
	 */
	public function onCreateWikiRename( $cwdb, $wiki ): void;
}
