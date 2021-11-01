<?php

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
		return $this->type === 'creation' ?
			'CreateWiki on ' . $this->config->get( 'Sitename' ) :
			'CreateWiki Notifications';
	}

	/**
	 * @return array
	 */
	private function getEmailTypes(): array {
		return [
			'creation',
			'deletion',
			'rename',
		];
	}

	/**
	 * @return array
	 */
	private function getEchoTypes(): array {
		return [
			'comment',
			'creation',
			'declined',
		];
	}

	/**
	 * @return array
	 */
	private function notifyServerAdministratorsTypes(): array {
		return [
			'rename',
			'deletion',
		];
	}

	/**
	 * @param array $data
	 * @param array $receivers
	 */
	public function sendNotification( array $data, array $receivers ) {
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
			EchoEvent::create( [
				'type' => $this->type,
				'extra' => $data['extra'],
				'agent' => $this->userFactory->newFromName( $receiver ),
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
				$user = $this->userFactory->newFromName( $receiver );

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
