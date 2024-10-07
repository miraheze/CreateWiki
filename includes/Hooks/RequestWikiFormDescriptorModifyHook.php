<?php

namespace Miraheze\CreateWiki\Hooks;

interface RequestWikiFormDescriptorModifyHook {

	/**
	 * @param array &$formDescriptor
	 * @return void
	 */
	public function onRequestWikiFormDescriptorModify( array &$formDescriptor ): void;
}
