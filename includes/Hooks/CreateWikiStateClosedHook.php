<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiStateClosedHook {

	/**
	 * @param string $dbname
	 * @return void
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiStateClosed( string $dbname ): void;
}
