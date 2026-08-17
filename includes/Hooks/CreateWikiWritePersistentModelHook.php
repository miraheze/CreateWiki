<?php

declare( strict_types = 1 );

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiWritePersistentModelHook {

	/**
	 * @param string $pipeline
	 *   The pipeline from PHPML.
	 *
	 * @return bool Whether to save using default handling.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiWritePersistentModel( string $pipeline ): bool;
}
