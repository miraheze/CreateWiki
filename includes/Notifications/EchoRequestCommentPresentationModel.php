<?php

namespace Miraheze\CreateWiki\Notifications;

use EchoDiscussionParser;
use EchoEventPresentationModel;
use RawMessage;

class EchoRequestCommentPresentationModel extends EchoEventPresentationModel {
	public function getIconType() {
		return 'chat';
	}

	public function getHeaderMessage() {
		return $this->msg( 'notification-header-request-comment' );
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

/**
 * @deprecated since 1.37
 */
class_alias( EchoRequestCommentPresentationModel::class, 'EchoRequestCommentPresentationModel' );
