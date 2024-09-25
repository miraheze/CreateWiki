<?php

namespace Miraheze\CreateWiki\Hooks;

use Wikimedia\Rdbms\DBConnRef;

interface CreateWikiDataFactoryBuilderHook {

	/**
	 * @param string $wiki
	 * @param DBConnRef $dbr
	 * @param array &$data
	 * @return void
	 */
	public function onCreateWikiDataFactoryBuilder( $wiki, $dbr, &$data ): void;
}
