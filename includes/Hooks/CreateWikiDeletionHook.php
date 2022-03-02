<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiDeletionHook {
	/**
	 * @param \Wikimedia\Rdbms\DBConnRef $cwdb
	 * @param string $old dbname
	 * @param string $new dbname
	 * @return void
	 */
	public function onCreateWikiDeletion( $cwdb, $old, $new ): void;
}
