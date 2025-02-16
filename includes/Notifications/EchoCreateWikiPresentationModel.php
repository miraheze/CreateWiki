<?php

namespace Miraheze\CreateWiki\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Message\Message;

class EchoCreateWikiPresentationModel extends EchoEventPresentationModel {

	/** @inheritDoc */
	public function getIconType(): string {
		return 'global';
	}

	/** @inheritDoc */
	public function getSubjectMessage(): Message {
		$msg = $this->msg( 'notification-createwiki-wiki-creation-email-subject' );
		$msg->params( $this->event->getExtraParam( 'sitename', 0 ) );

		return $msg;
	}

	/** @inheritDoc */
	public function getHeaderMessage(): Message {
		$msg = $this->msg( 'notification-header-wiki-creation' );
		$msg->params( $this->event->getExtraParam( 'sitename', 0 ) );

		return $msg;
	}

	/** @inheritDoc */
	public function getPrimaryLink(): bool {
		return false;
	}

	/** @inheritDoc */
	public function getSecondaryLinks(): array {
		$visitLink = [
			'url' => $this->event->getExtraParam( 'wiki-url', 0 ),
			'label' => $this->msg( 'notification-createwiki-wiki-creation-visitwiki-label' )->text(),
			'prioritized' => true,
		];

		return [ $visitLink ];
	}
}
