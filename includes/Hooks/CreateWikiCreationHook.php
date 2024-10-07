<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiCreationHook {

	/**
	 * @param string $dbname
	 * @param bool $private
	 * @return void
	 */
	public function onCreateWikiCreation( string $dbname, bool $private ): void;
}
