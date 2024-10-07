<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiStatePrivateHook {

	/**
	 * @param string $dbname
	 * @return void
	 */
	public function onCreateWikiStatePrivate( string $dbname ): void;
}
