<?php

namespace Miraheze\CreateWiki\Hooks;

use Wikimedia\Rdbms\DBConnRef;

interface CreateWikiCreationHook {
	/**
	 * @param DBConnRef $cwdb
	 * @param string $wiki dbname
	 * @param bool $private
	 * @return void
	 */
	public function onCreateWikiCreation( $cwdb, $wiki, $private ): void;
}
