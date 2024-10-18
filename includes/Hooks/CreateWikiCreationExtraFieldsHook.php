<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiCreationExtraFieldsHook {

	/**
	 * @param array &$extraFields
	 * @return void
	 */
	public function onCreateWikiCreationExtraFields( array &$extraFields ): void;
}
