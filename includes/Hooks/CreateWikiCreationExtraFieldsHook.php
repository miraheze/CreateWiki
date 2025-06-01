<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiCreationExtraFieldsHook {

	/**
	 * @param array &$extraFields
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiCreationExtraFields( array &$extraFields ): void;
}
