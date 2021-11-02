<?php

use MediaWiki\User\UserFactory;

class NotificationsManager {
	/** @var Config */
	private $config;

	/** @var UserFactory */
	private $userFactory;

	/** @var string */
	private $type;

	/**
	 * @param Config $config
	 */
	public function __construct( Config $config, UserFactory $userFactory ) {
		$this->config = $config;
		$this->userFactory = $userFactory;
	}

	/**
	 * @return string
	 */
	private function getFromName(): string {
		if ( $this->type === 'closure' ) {
			return wfMessage( 'createwiki-close-email-sender' )->text();
		}

		if ( $this->type === 'wiki-creation' ) {
			return 'CreateWiki on ' . $this->config->get( 'Sitename' );
		}

		return 'CreateWiki Notifications';
	}

	/**
	 * @return array
	 */
	private function getEmailTypes(): array {
		return [
			'closure',
			'deletion',
			'wiki-creation',
			'wiki-rename',
		];
	}

	/**
	 * @return array
	 */
	private function getEchoTypes(): array {
		return [
			'request-comment',
			'request-declined',
			'wiki-creation',
		];
	}

	/**
	 * @return array
	 */
	private function notifyServerAdministratorsTypes(): array {
		return [
			'deletion',
			'wiki-rename',
		];
	}

	/**
	 * @param array $data
	 * @param array $receivers
	 */
	public function sendNotification( array $data, array $receivers = [] ) {
		$this->type = $data['type'];

		if ( in_array( $this->type, $this->getEchoTypes() ) {
			$this->sendEchoNotification( $receivers );
		}

		if ( in_array( $this->type, $this->getEmailTypes() ) {
			$this->sendEmailNotification( $data, $receivers );
		}
	}

	/**
	 * @param array $data
	 * @param array $receivers
	 */
	private function sendEchoNotification( array $data, array $receivers ) {
		foreach ( $receivers as $receiver ) {

			$user = !is_object( $receiver ) ? $this->userFactory->newFromName( $receiver ) : $receiver;
			EchoEvent::create( [
				'type' => $this->type,
				'extra' => $data['extra'] + [ 'notifyAgent' => true ],
				'agent' => $user,
			] );
		}
	}

	/**
	 * @param array $data
	 * @param array $receivers
	 */
	private function sendEmailNotification( array $data, array $receivers ) {
		DeferredUpdates::addCallableUpdate( function () use ( $data, $receivers ) {
			$notifyEmails = [];

			foreach ( $receivers as $receiver ) {
				$user = !is_object( $receiver ) ? $this->userFactory->newFromName( $receiver ) : $receiver;

				if ( !$user ) {
					continue;
				}

				$notifyEmails[] = MailAddress::newFromUser( $user );
			}

			if ( in_array( $this->type, $this->notifyServerAdministratorsTypes() ) ) {
				$notifyEmails[] = new MailAddress( $this->config->get( 'CreateWikiNotificationEmail' ), 'Server Administrators' );
			}

			$from = new MailAddress( $this->config->get( 'PasswordSender' ), $this->getFromName() );

			UserMailer::send(
				$notifyEmails,
				$from,
				$data['subject'],
				$data['body']
			);
		} );
	}
}
