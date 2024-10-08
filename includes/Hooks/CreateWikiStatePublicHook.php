<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiStatePublicHook {

	/**
	 * @param string $dbname
	 * @return void
	 */
	public function onCreateWikiStatePublic( string $dbname ): void;
}
