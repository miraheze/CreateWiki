<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiSetContainersAccessFailedHook {

	/**
	 * @param string $dir
	 * @param string $zone
	 * @return void
	 */
	public function onCreateWikiSetContainersAccessFailed( string $dir, string $zone ): void;
}
