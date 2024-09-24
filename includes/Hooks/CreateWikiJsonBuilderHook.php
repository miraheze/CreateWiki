<?php

namespace Miraheze\CreateWiki\Hooks;

use Wikimedia\Rdbms\DBConnRef;

interface CreateWikiJsonBuilderHook {

	/**
	 * @param string $wiki
	 * @param DBConnRef $dbr
	 * @param array &$jsonArray
	 * @return void
	 */
	public function onCreateWikiJsonBuilder( $wiki, $dbr, &$jsonArray ): void;
}
