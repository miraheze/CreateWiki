<?php

declare( strict_types = 1 );

namespace Miraheze\CreateWiki\Hooks;

use MediaWiki\User\User;
use Miraheze\CreateWiki\Services\WikiRequestManager;

interface RequestWikiQueueFormDescriptorModifyHook {

	/**
	 * @param array &$formDescriptor
	 *   The HTMLForm descriptor array. This is passed by reference and may be modified
	 *   to add new form fields or update existing ones. Array keys should be unique field names,
	 *   and values should conform to HTMLForm field configuration.
	 * @param User $user
	 *   The User onject for the current user that is viewing the request.
	 * @param WikiRequestManager $wikiRequestManager
	 *   The object for the WikiRequestManager for the current request to retrieve
	 *   or modify the data for fields for the current request.
	 *
	 * @return void This hook must not abort, it must return no value.
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onRequestWikiQueueFormDescriptorModify(
		array &$formDescriptor,
		User $user,
		WikiRequestManager $wikiRequestManager
	): void;
}
