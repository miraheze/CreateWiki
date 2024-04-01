<?php

namespace Miraheze\CreateWiki\Notifications;

use EchoDiscussionParser;
use EchoEventPresentationModel;
use RawMessage;

class EchoRequestMoreDetailsPresentationModel extends EchoEventPresentationModel {
	public function getIconType() {
		return 'global';
	}

	public function getHeaderMessage() {
		return $this->msg( 'notification-header-request-moredetails' );
	}

	public function getBodyMessage() {
		$reason = $this->event->getExtraParam( 'reason' );
		$text = EchoDiscussionParser::getTextSnippet( $reason, $this->language );

		return new RawMessage( "$1", [ $text ] );
	}

	public function getPrimaryLink() {
		return false;
	}

	public function getSecondaryLinks() {
		$visitLink = [
			'url' => $this->event->getExtraParam( 'request-url', 0 ),
			'label' => $this->msg( 'notification-createwiki-visit-request' )->text(),
			'prioritized' => true,
		];

		return [ $visitLink ];
	}
}
