<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiCreationHook {

	/**
	 * @param string $dbname
	 *   The target wiki's database name (e.g., "examplewiki").
	 * @param bool $private
	 *   Whether the new wiki is initially set to private.
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiCreation( string $dbname, bool $private ): void;
}
