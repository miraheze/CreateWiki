<?php

namespace Miraheze\CreateWiki\Notifications;

use MediaWiki\Extension\Notifications\DiscussionParser;
use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Language\RawMessage;
use MediaWiki\Message\Message;

class EchoRequestDeclinedPresentationModel extends EchoEventPresentationModel {

	/**
	 * @inheritDoc
	 */
	public function getIconType(): string {
		return 'request-declined';
	}

	/**
	 * @inheritDoc
	 */
	public function getHeaderMessage(): Message {
		return $this->msg( 'notification-header-request-declined' );
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyMessage(): RawMessage {
		$reason = $this->event->getExtraParam( 'reason' );
		$text = DiscussionParser::getTextSnippet( $reason, $this->language );

		return new RawMessage( '$1', [ $text ] );
	}

	/**
	 * @inheritDoc
	 */
	public function getPrimaryLink(): bool {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function getSecondaryLinks(): array {
		$visitLink = [
			'url' => $this->event->getExtraParam( 'request-url', 0 ),
			'label' => $this->msg( 'notification-createwiki-visit-request' )->text(),
			'prioritized' => true,
		];

		return [ $visitLink ];
	}
}
