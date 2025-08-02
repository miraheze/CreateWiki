<?php

namespace Miraheze\CreateWiki\Hooks;

interface RequestWikiFormDescriptorModifyHook {

	/**
	 * @param array &$formDescriptor
	 *   The HTMLForm descriptor array. This is passed by reference and may be modified
	 *   to add new form fields or update existing ones. Array keys should be unique field names,
	 *   and values should conform to HTMLForm field configuration.
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onRequestWikiFormDescriptorModify( array &$formDescriptor ): void;
}
