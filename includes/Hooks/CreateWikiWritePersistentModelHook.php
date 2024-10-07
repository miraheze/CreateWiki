<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiWritePersistentModelHook {

	/**
	 * @param string $pipeline
	 * @return bool
	 */
	public function onCreateWikiWritePersistentModel( string $pipeline ): bool;
}
