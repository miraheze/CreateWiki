<?php

namespace Miraheze\CreateWiki\Loadout;

use MediaWiki\Message\Message;
use MessageLocalizer;
use function in_array;

class LoadoutFormBuilder {

	public function __construct(
		private readonly LoadoutManager $loadoutManager,
		private readonly MessageLocalizer $messageLocalizer,
	) {
	}

	public function getFormDescriptor(): array {
		return [
			'type' => 'select',
			'cssclass' => 'ext-createwiki-infuse',
			'label-message' => 'createwiki-label-loadout',
			'help-messages' => $this->buildHelpMessages(),
			'options-messages' => $this->buildOptionsMessages(),
			'validation-callback' => [ $this, 'validateLoadout' ],
			'default' => '',
		];
	}

	public function validateLoadout( ?string $loadout ): Message|true {
		// Empty loadout is also fine
		if ( $loadout && !in_array( $loadout, $this->loadoutManager->getLoadoutNames(), true ) ) {
			return $this->messageLocalizer->msg( 'createwiki-error-invalid-loadout' );
		}

		return true;
	}

	/**
	 * @return array<string,string> Label message key => loadout name, as HTMLForm expects.
	 */
	private function buildOptionsMessages(): array {
		$options = [ 'createwiki-label-loadout-none' => '' ];
		foreach ( $this->loadoutManager->getLoadoutNames() as $loadout ) {
			$options["createwiki-label-loadout-$loadout"] = $loadout;
		}

		return $options;
	}

	/**
	 * The intro message is followed by one list item per loadout. Each item is built
	 * from a shared wrapper message taking the loadout name as $1, which it uses to
	 * transclude that loadout's label and description.
	 *
	 * @return array<int,string|array> Help message specifiers, as HTMLForm expects.
	 */
	private function buildHelpMessages(): array {
		$messages = [ 'createwiki-help-loadout' ];
		foreach ( [ 'none', ...$this->loadoutManager->getLoadoutNames() ] as $loadout ) {
			$messages[] = [ 'createwiki-help-loadout-item', $loadout ];
		}

		return $messages;
	}
}
