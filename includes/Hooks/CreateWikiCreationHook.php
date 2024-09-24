<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiCreationHook {

	/**
	 * @param string $wiki dbname
	 * @param bool $private
	 * @return void
	 */
	public function onCreateWikiCreation( $wiki, $private ): void;
}
