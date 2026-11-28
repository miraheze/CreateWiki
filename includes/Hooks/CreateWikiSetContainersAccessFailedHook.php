<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiSetContainersAccessFailedHook {

	/**
	 * @param string $dir The current storage directory.
	 * @param string $zone The current zone.
	 * @return bool Whether to retry the script again after running this hook.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiSetContainersAccessFailed( string $dir, string $zone ): bool;
}
