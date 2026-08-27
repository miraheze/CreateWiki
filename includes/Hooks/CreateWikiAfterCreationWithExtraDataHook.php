<?php

declare( strict_types = 1 );

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiAfterCreationWithExtraDataHook {

	/**
	 * @param array $extraData
	 *   The extra data fields that you can retrieve their values
	 *   and use after the wiki has been created.
	 * @param string $dbname
	 *   The target wiki's database name (e.g., "examplewiki").
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiAfterCreationWithExtraData( array $extraData, string $dbname ): void;
}
