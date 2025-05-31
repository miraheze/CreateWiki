<?php

namespace Miraheze\CreateWiki\Services;

use MailAddress;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\MainConfigNames;
use MediaWiki\User\UserFactory;
use MessageLocalizer;
use Miraheze\CreateWiki\ConfigNames;
use stdClass;
use UserMailer;
use function in_array;
use function is_object;

class CreateWikiNotificationsManager {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::EmailNotifications,
		ConfigNames::NotificationEmail,
		ConfigNames::UseEchoNotifications,
		MainConfigNames::PasswordSender,
		MainConfigNames::Sitename,
	];

	private const ECHO_TYPES = [
		'request-comment',
		'request-declined',
		'request-moredetails',
		'wiki-creation',
	];

	private const EMAIL_TYPES = [
		'closure',
		'deletion',
		'wiki-creation',
		'wiki-rename',
	];

	private const SERVER_ADMIN_TYPES = [
		'deletion',
		'wiki-rename',
	];

	private string $type;

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly MessageLocalizer $messageLocalizer,
		private readonly ServiceOptions $options,
		private readonly UserFactory $userFactory
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	private function getFromName(): string {
		if ( $this->type === 'closure' ) {
			return $this->messageLocalizer->msg( 'createwiki-close-email-sender' )
				->inContentLanguage()->text();
		}

		if ( $this->type === 'wiki-creation' ) {
			return 'CreateWiki on ' . $this->options->get( MainConfigNames::Sitename );
		}

		return 'CreateWiki Notifications';
	}

	public function notifyBureaucrats( array $data, string $dbname ): void {
		$dbr = $this->databaseUtils->getRemoteWikiReplicaDB( $dbname );

		$bureaucrats = $dbr->newSelectQueryBuilder()
			->select( [ 'user_email', 'user_name' ] )
			->from( 'user_groups' )
			->join( 'user', null, [ 'user_id = ug_user' ] )
			->where( [
				'ug_group' => 'bureaucrat',
			] )
			->distinct()
			->caller( __METHOD__ )
			->fetchResultSet();

		$emails = [];
		foreach ( $bureaucrats as $user ) {
			if ( !$user instanceof stdClass ) {
				// Skip unexpected row
				continue;
			}

			$emails[] = new MailAddress( $user->user_email, $user->user_name );
		}

		$this->sendNotification( $data, $emails );
	}

	public function sendNotification( array $data, array $receivers ): void {
		$this->type = $data['type'];

		if (
			$this->options->get( ConfigNames::UseEchoNotifications ) &&
			in_array( $this->type, self::ECHO_TYPES, true )
		) {
			$this->sendEchoNotification( $data, $receivers );
		}

		if (
			$this->options->get( ConfigNames::EmailNotifications ) &&
			in_array( $this->type, self::EMAIL_TYPES, true )
		) {
			$this->sendEmailNotification( $data, $receivers );
		}
	}

	private function sendEchoNotification( array $data, array $receivers ): void {
		foreach ( $receivers as $receiver ) {
			$user = is_object( $receiver ) ? $receiver :
				$this->userFactory->newFromName( $receiver );

			if ( !$user ) {
				continue;
			}

			Event::create( [
				'type' => $this->type,
				'extra' => $data['extra'] + [ 'notifyAgent' => true ],
				'agent' => $user,
			] );
		}
	}

	private function sendEmailNotification( array $data, array $receivers ): void {
		DeferredUpdates::addCallableUpdate( function () use ( $data, $receivers ) {
			$notifyEmails = [];

			foreach ( $receivers as $receiver ) {
				if ( $receiver instanceof MailAddress ) {
					$notifyEmails[] = $receiver;
					continue;
				}

				$user = is_object( $receiver ) ? $receiver :
					$this->userFactory->newFromName( $receiver );

				if ( !$user ) {
					continue;
				}

				$notifyEmails[] = MailAddress::newFromUser( $user );
			}

			if ( in_array( $this->type, self::SERVER_ADMIN_TYPES, true ) ) {
				$notifyEmails[] = new MailAddress(
					$this->options->get( ConfigNames::NotificationEmail ),
					'Server Administrators'
				);
			}

			$from = new MailAddress(
				$this->options->get( MainConfigNames::PasswordSender ),
				$this->getFromName()
			);

			UserMailer::send(
				$notifyEmails,
				$from,
				$data['subject'],
				$data['body']
			);
		} );
	}
}
