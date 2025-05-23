<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiWritePersistentModelHook {

	/**
	 * @param string $pipeline
	 * @return bool
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiWritePersistentModel( string $pipeline ): bool;
}
