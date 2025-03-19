<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiStatePublicHook {

	/**
	 * @param string $dbname
	 * @return void
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiStatePublic( string $dbname ): void;
}
