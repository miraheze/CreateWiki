<?php

namespace Miraheze\CreateWiki\Hooks;

interface RequestWikiNewRequestHook {

	/**
	 * @param int $id
	 *   The newly created wiki request's ID.
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onRequestWikiNewRequest( int $id ): void;
}
