<?php

namespace Miraheze\CreateWiki\Hooks\Handlers;

use MediaWiki\User\User;
use Miraheze\CreateWiki\Hooks\CreateWikiAfterCreationWithExtraDataHook;
use Miraheze\CreateWiki\Hooks\CreateWikiCreationExtraFieldsHook;
use Miraheze\CreateWiki\Hooks\RequestWikiFormDescriptorModifyHook;
use Miraheze\CreateWiki\Hooks\RequestWikiQueueFormDescriptorModifyHook;
use Miraheze\CreateWiki\Loadout\LoadoutFormBuilder;
use Miraheze\CreateWiki\Loadout\LoadoutManager;
use Miraheze\CreateWiki\RequestWiki\RequestWikiFormUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;

class Loadout implements
	CreateWikiAfterCreationWithExtraDataHook,
	CreateWikiCreationExtraFieldsHook,
	RequestWikiFormDescriptorModifyHook,
	RequestWikiQueueFormDescriptorModifyHook
{

	public function __construct(
		private readonly LoadoutFormBuilder $formBuilder,
		private readonly LoadoutManager $loadoutManager,
	) {
	}

	/** @inheritDoc */
	public function onCreateWikiCreationExtraFields( array &$extraFields ): void {
		$extraFields[] = LoadoutManager::FIELD_NAME;
	}

	/** @inheritDoc */
	public function onCreateWikiAfterCreationWithExtraData( array $extraData, string $dbname ): void {
		$loadout = $extraData[LoadoutManager::FIELD_NAME] ?? '';
		if ( !$loadout ) {
			return;
		}

		$this->loadoutManager->applyLoadout( $loadout, $dbname );
	}

	/** @inheritDoc */
	public function onRequestWikiFormDescriptorModify( array &$formDescriptor ): void {
		if ( !$this->formBuilder->isEnabled() ) {
			return;
		}

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'category',
			newKey: LoadoutManager::FIELD_NAME,
			newField: $this->formBuilder->getFormDescriptor()
		);
	}

	/** @inheritDoc */
	public function onRequestWikiQueueFormDescriptorModify(
		array &$formDescriptor,
		User $user,
		WikiRequestManager $wikiRequestManager
	): void {
		if ( !$this->formBuilder->isEnabled() ) {
			return;
		}

		$loadout = $wikiRequestManager->getExtraFieldData( LoadoutManager::FIELD_NAME );
		if ( $loadout ) {
			$formDescriptor[LoadoutManager::FIELD_NAME] = [
				'type' => 'info',
				'label-message' => 'createwiki-label-loadout',
				'section' => 'details',
				'default' => $loadout,
			];
		}

		$loadoutDescriptor = $this->formBuilder->getFormDescriptor();
		$loadoutDescriptor['default'] = $loadout ?? '';
		$loadoutDescriptor['disabled'] = $wikiRequestManager->isLocked();
		$loadoutDescriptor['section'] = 'editing';

		$formDescriptor['edit-' . LoadoutManager::FIELD_NAME] = $loadoutDescriptor;
	}
}
