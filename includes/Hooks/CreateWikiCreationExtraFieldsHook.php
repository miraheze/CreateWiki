<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiCreationExtraFieldsHook {

	/**
	 * @param array &$extraFields
	 * @return void
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiCreationExtraFields( array &$extraFields ): void;
}
