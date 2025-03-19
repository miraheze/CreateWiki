<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiSetContainersAccessFailedHook {

	/**
	 * @param string $dir
	 * @param string $zone
	 * @return bool
	 */
	public function onCreateWikiSetContainersAccessFailed( string $dir, string $zone ): bool;
}
