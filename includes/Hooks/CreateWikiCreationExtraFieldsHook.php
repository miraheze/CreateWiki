<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiCreationExtraFieldsHook {

	/**
	 * @param array &$extraFields
	 *   The extra data fields to pass from cw_requests to cw_wikis.
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiCreationExtraFields( array &$extraFields ): void;
}
