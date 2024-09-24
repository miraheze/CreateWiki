<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiStateOpenHook {

	/**
	 * @param string $dbname
	 * @return void
	 */
	public function onCreateWikiStateOpen( $dbname ): void;
}
