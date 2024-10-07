<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiStateClosedHook {

	/**
	 * @param string $dbname
	 * @return void
	 */
	public function onCreateWikiStateClosed( string $dbname ): void;
}
