<?php

declare( strict_types = 1 );

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiStateClosedHook {

	/**
	 * @param string $dbname
	 *   The target wiki's database name (e.g., "examplewiki").
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiStateClosed( string $dbname ): void;
}
