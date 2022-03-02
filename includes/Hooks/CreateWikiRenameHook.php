<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiRenameHook {
	/**
	 * @param \Wikimedia\Rdbms\DBConnRef $cwdb
	 * @param string $old dbname
	 * @param string $new dbname
	 * @return void
	 */
	public function onCreateWikiRename( $cwdb, $old, $new ): void;
}
