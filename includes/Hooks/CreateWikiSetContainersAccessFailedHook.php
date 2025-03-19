<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiSetContainersAccessFailedHook {

	/**
	 * @param string $dir
	 * @param string $zone
	 * @param array $errors
	 * @return bool
	 */
	public function onCreateWikiSetContainersAccessFailed(
		string $dir, string $zone, array $errors
	): bool;
}
