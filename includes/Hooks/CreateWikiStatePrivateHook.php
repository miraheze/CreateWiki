<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiStatePrivateHook {

	/**
	 * @param string $dbname
	 * @return void
	 */
	public function onCreateWikiStatePrivate( $dbname ): void;
}
