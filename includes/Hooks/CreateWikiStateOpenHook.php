<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiStateOpenHook {
	/**
	 * @param string $dbname
	 * @return bool void
	 */
	public function onCreateWikiStateOpen( $dbname ): void;
}
