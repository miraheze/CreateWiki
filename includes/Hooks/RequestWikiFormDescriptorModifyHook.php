<?php

namespace Miraheze\CreateWiki\Hooks;

interface RequestWikiFormDescriptorModifyHook {

	/**
	 * @param array &$formDescriptor
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onRequestWikiFormDescriptorModify( array &$formDescriptor ): void;
}
