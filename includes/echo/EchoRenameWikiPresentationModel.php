<?php
class EchoRenameWikiPresentationModel extends EchoEventPresentationModel {
	public function getIconType() {
		return 'global';
	}

	public function getSubjectMessage() {
		$msg = $this->msg( 'notification-createwiki-wiki-rename-subject' );
		$msg->params( $this->event->getExtraParam( 'sitename', 0 ) );

		return $msg;
	}

	public function getHeaderMessage() {
		$msg = $this->msg( 'notification-header-wiki-rename' );
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
