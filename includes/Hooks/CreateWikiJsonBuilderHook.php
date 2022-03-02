<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiJsonBuilderHook {
	/**
	 * @param string $wiki
	 * @param \Wikimedia\Rdbms\DBConnRef $dbr
	 * @param array &$jsonArray
	 * @return void
	 */
	public function onCreateWikiJsonBuilder( $wiki, $dbr, &$jsonArray ): void;
}
