<?php

declare( strict_types = 1 );

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiRemoteWikiCommitHook {

	/**
	 * @param string $dbname
	 *   The target wiki's database name (e.g., "examplewiki").
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiRemoteWikiCommit( string $dbname ): void;
}
