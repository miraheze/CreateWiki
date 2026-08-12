<?php

namespace Miraheze\CreateWiki\Hooks;

interface CreateWikiCreationOptionsHook {

	/**
	 * Allows modifying which of the built-in steps of the wiki creation process
	 * are performed for a given wiki.
	 *
	 * Currently supported options:
	 * 1. createMainPage (bool, default true): whether to populate the Main Page of
	 *    the new wiki.
	 *
	 * @param string $dbname
	 *   The created wiki's database name.
	 * @param array $extra
	 *   The extra data submitted with the wiki request from the
	 *   CreateWikiCreationExtraFields hook.
	 * @param array &$options
	 *   Options to modify, as documented above.
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onCreateWikiCreationOptions(
		string $dbname,
		array $extra,
		array &$options
	): void;
}
