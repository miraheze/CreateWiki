<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiStatePrivateHook {

	/**
	 * @param string $dbname
	 * @return void
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiStatePrivate( string $dbname ): void;
}
