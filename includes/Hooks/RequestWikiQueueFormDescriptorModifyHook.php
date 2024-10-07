<?php

namespace Miraheze\CreateWiki\Hooks;

use MediaWiki\User\User;
use Miraheze\CreateWiki\Services\WikiRequestManager;

interface RequestWikiQueueFormDescriptorModifyHook {

	/**
	 * @param array &$formDescriptor
	 * @param User $user
	 * @param WikiRequestManager $wikiRequestManager
	 * @return void
	 */
	public function onRequestWikiQueueFormDescriptorModify(
		array &$formDescriptor,
		User $user,
		WikiRequestManager $wikiRequestManager
	): void;
}
