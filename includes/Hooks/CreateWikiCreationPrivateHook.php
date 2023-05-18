<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiCreationPrivateHook {
	/**
	 * @param string $wiki dbname
	 * @param bool $private
	 * @param string $defaultPrivateGroup
	 * @return void
	 */
	public function onCreateWikiCreationPrivate( $wiki, $private, $defaultPrivateGroup ): void;
}
