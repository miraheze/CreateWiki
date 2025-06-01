<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiStateClosedHook {

	/**
	 * @param string $dbname
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiStateClosed( string $dbname ): void;
}
