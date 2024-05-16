<?php

namespace Miraheze\CreateWiki\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;

class EchoCreateWikiPresentationModel extends EchoEventPresentationModel {
	public function getIconType() {
		return 'global';
	}

	public function getSubjectMessage() {
		$msg = $this->msg( 'notification-createwiki-wiki-creation-email-subject' );
		$msg->params( $this->event->getExtraParam( 'sitename', 0 ) );

		return $msg;
	}

	public function getHeaderMessage() {
		$msg = $this->msg( 'notification-header-wiki-creation' );
		$msg->params( $this->event->getExtraParam( 'sitename', 0 ) );

		return $msg;
	}

	public function getPrimaryLink() {
		return false;
	}

	public function getSecondaryLinks() {
		$visitLink = [
			'url' => $this->event->getExtraParam( 'wiki-url', 0 ),
			'label' => $this->msg( 'notification-createwiki-wiki-creation-visitwiki-label' )->text(),
			'prioritized' => true,
		];

		return [ $visitLink ];
	}
}
