<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiWritePersistentModelHook {

	/**
	 * @param string $pipeline
	 * @return bool Whether to save using default handling.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiWritePersistentModel( string $pipeline ): bool;
}
