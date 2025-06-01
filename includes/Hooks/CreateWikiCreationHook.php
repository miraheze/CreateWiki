<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiCreationHook {

	/**
	 * @param string $dbname
	 * @param bool $private
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiCreation( string $dbname, bool $private ): void;
}
