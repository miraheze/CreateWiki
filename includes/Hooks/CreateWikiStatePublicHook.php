<?php

declare( strict_types = 1 );

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiStatePublicHook {

	/**
	 * @param string $dbname
	 *   The target wiki's database name (e.g., "examplewiki").
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiStatePublic( string $dbname ): void;
}
