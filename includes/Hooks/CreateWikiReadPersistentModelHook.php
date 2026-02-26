<?php

declare( strict_types = 1 );

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiReadPersistentModelHook {

	/**
	 * @param string &$pipeline
	 *   The pipeline from PHPML.
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiReadPersistentModel( string &$pipeline ): void;
}
