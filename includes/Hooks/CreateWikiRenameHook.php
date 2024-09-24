<?php

namespace Miraheze\CreateWiki\Hooks;

use Wikimedia\Rdbms\DBConnRef;

interface CreateWikiRenameHook {

	/**
	 * @param DBConnRef $cwdb
	 * @param string $old dbname
	 * @param string $new dbname
	 * @return void
	 */
	public function onCreateWikiRename( $cwdb, $old, $new ): void;
}
