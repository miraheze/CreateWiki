<?php

namespace Miraheze\CreateWiki\Hooks;

use MediaWiki\User\User;
use Miraheze\CreateWiki\Services\WikiRequestManager;

interface RequestWikiQueueFormDescriptorModifyHook {

	/**
	 * @param array &$formDescriptor
	 * @param User $user
	 * @param WikiRequestManager $wikiRequestManager
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onRequestWikiQueueFormDescriptorModify(
		array &$formDescriptor,
		User $user,
		WikiRequestManager $wikiRequestManager
	): void;
}
