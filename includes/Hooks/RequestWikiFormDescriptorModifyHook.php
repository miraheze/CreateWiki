<?php

namespace Miraheze\CreateWiki\Hooks;

interface RequestWikiFormDescriptorModifyHook {

	/**
	 * @param array &$formDescriptor
	 * @return void
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onRequestWikiFormDescriptorModify( array &$formDescriptor ): void;
}
