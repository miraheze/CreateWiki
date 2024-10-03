<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiAfterCreationWithExtraDataHook {

	/**
	 * @param array $extraData
	 * @param string $dbname
	 * @return void
	 */
	public function onCreateWikiAfterCreationWithExtraData( array $extraData, string $dbname ): void;
}
