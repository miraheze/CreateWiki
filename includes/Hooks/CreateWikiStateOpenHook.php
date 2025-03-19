<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiStateOpenHook {

	/**
	 * @param string $dbname
	 * @return void
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiStateOpen( string $dbname ): void;
}
