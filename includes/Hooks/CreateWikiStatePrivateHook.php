<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiStatePrivateHook {

	/**
	 * @param string $dbname
	 *   The target wiki's database name (e.g., "examplewiki").
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiStatePrivate( string $dbname ): void;
}
