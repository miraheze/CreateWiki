<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiReadPersistentModelHook {

	/**
	 * @param string &$pipeline
	 * @return void
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiReadPersistentModel( string &$pipeline ): void;
}
