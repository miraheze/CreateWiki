<?php
class EchoRequestDeclinedPresentationModel extends EchoEventPresentationModel {
	public function getIconType() {
		// No icon yet
		return 'placeholder';
	}

	public function getHeaderMessage() {
		$msg = $this->msg( 'notification-header-request-declined' );

		return $msg;
	}

	public function getBodyMessage() {
		$reason = $this->event->getExtraParam( 'reason' );
		$text = EchoDiscussionParser::getTextSnipper( $reason, $this->language );

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
