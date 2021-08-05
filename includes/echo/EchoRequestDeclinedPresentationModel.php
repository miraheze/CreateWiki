<?php
class EchoRequestDeclinedPresentationModel extends EchoEventPresentationModel {
	public function getIconType() {
		// No icon yet
		return 'placeholder';
	}

	public function getHeaderMessage() {
		return $this->msg( 'notification-header-request-declined' );
	}

	public function getBodyMessage() {
		$comment = $this->event->getExtraParam( 'comment' );
		$text = EchoDiscussionParser::getTextSnippet( $comment, $this->language );

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
