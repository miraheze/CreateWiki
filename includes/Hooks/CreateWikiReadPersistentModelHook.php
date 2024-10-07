<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiReadPersistentModelHook {

	/**
	 * @param string &$pipeline
	 * @return void
	 */
	public function onCreateWikiReadPersistentModel( string &$pipeline ): void;
}
