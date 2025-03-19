<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiAfterCreationWithExtraDataHook {

	/**
	 * @param array $extraData
	 * @param string $dbname
	 * @return void
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiAfterCreationWithExtraData( array $extraData, string $dbname ): void;
}
