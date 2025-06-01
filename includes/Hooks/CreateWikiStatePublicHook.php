<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiStatePublicHook {

	/**
	 * @param string $dbname
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiStatePublic( string $dbname ): void;
}
