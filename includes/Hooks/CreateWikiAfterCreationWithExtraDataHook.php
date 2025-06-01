<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiAfterCreationWithExtraDataHook {

	/**
	 * @param array $extraData
	 * @param string $dbname
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiAfterCreationWithExtraData( array $extraData, string $dbname ): void;
}
