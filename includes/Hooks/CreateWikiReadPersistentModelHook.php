<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiReadPersistentModelHook {

	/**
	 * @param string &$pipeline
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiReadPersistentModel( string &$pipeline ): void;
}
