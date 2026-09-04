<?php

namespace Miraheze\CreateWiki\Loadout;

use MediaWiki\Html\Html;
use MediaWiki\Message\Message;
use MessageLocalizer;
use function implode;
use function in_array;

class LoadoutFormBuilder {

	public function __construct(
		private readonly LoadoutManager $loadoutManager,
		private readonly MessageLocalizer $messageLocalizer,
	) {
	}

	public function getFormDescriptor(): array {
		$options = $this->buildOptions();
		return [
			'type' => 'select',
			'label-message' => 'createwiki-label-loadout',
			'help-raw' => $this->buildHelpText( $options ),
			'options' => $options,
			'validation-callback' => [ $this, 'validateLoadout' ],
			'default' => '',
		];
	}

	public function validateLoadout( ?string $loadout ): bool|Message {
		// Empty loadout is also fine
		if ( $loadout && !in_array( $loadout, $this->loadoutManager->getLoadoutNames(), true ) ) {
			return $this->messageLocalizer->msg( 'createwiki-error-invalid-loadout' );
		}

		return true;
	}

	/**
	 * @return array<string,string> Label => loadout name, as HTMLForm expects.
	 */
	private function buildOptions(): array {
		$noneLabel = $this->messageLocalizer->msg( 'createwiki-label-loadout-none' )->text();
		$options = [ $noneLabel => '' ];

		foreach ( $this->loadoutManager->getLoadoutNames() as $loadout ) {
			$label = $this->messageLocalizer->msg( "createwiki-label-loadout-$loadout" )->text();
			$options[$label] = $loadout;
		}

		return $options;
	}

	/**
	 * @param array<string,string> $options
	 */
	private function buildHelpText( array $options ): string {
		$items = '';
		foreach ( $options as $label => $loadout ) {
			$messageKey = 'createwiki-help-loadout-' . ( $loadout === '' ? 'none' : $loadout );
			// Format: "label: description"
			$items .= Html::rawElement( 'li', [], implode( '', [
				Html::element( 'strong', [], $label ),
				$this->messageLocalizer->msg( 'colon-separator' )->escaped(),
				$this->messageLocalizer->msg( $messageKey )->parse(),
			] ) );
		}

		return $this->messageLocalizer->msg( 'createwiki-help-loadout' )->parse() .
			Html::rawElement( 'ul', [ 'class' => 'createwiki-help-loadout-list' ], $items );
	}
}
