<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\Services\CreateWikiNotificationsManager;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use function strtolower;

class RenameWiki extends Maintenance {

	private CreateWikiNotificationsManager $notificationsManager;
	private WikiManagerFactory $wikiManagerFactory;

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Renames a wiki from it\'s original name to a new name. ' .
			'Will NOT perform core database operations so run AFTER new ' .
			'database exists and while old one still exists.'
		);

		$this->addOption( 'rename', 'Performs the rename. If not, will output rename information.' );
		$this->addOption( 'old', 'Old wiki database name', true, true );
		$this->addOption( 'new', 'New wiki database name', true, true );

		$this->addOption( 'user',
			'Username or reference name of the person running this script. ' .
			'Will be used in tracking and notification internally.',
		true, true );

		$this->requireExtension( 'CreateWiki' );
	}

	private function initServices(): void {
		$services = $this->getServiceContainer();
		$this->notificationsManager = $services->get( 'CreateWikiNotificationsManager' );
		$this->wikiManagerFactory = $services->get( 'WikiManagerFactory' );
	}

	public function execute(): void {
		$this->initServices();
		$old = strtolower( $this->getOption( 'old' ) );
		$new = strtolower( $this->getOption( 'new' ) );

		if ( $this->hasOption( 'rename' ) ) {
			$this->output( "Renaming $old to $new. If this is wrong, Ctrl-C now!\n" );

			// let's count down JUST to be safe!
			$this->countDown( 10 );

			$wikiManager = $this->wikiManagerFactory->newInstance( $old );
			$rename = $wikiManager->rename( newDatabaseName: $new );

			if ( $rename ) {
				$this->fatalError( $rename );
			}
		} else {
			$this->output( "Wiki $old will be renamed to $new\n" );
		}

		$this->output( "Done.\n" );

		if ( $this->hasOption( 'rename' ) ) {
			$user = $this->getOption( 'user' );

			$message = "Hello!\nThis is an automatic notification from CreateWiki notifying you that " .
				"just now $user has renamed the following wiki from CreateWiki and " .
				"associated extensions - From $old to $new.";

			$notificationData = [
				'type' => 'wiki-rename',
				'subject' => 'Wiki Rename Notification',
				'body' => $message,
			];

			$this->notificationsManager->sendNotification(
				data: $notificationData,
				// No receivers, it will send to configured email
				receivers: []
			);
		}
	}
}

// @codeCoverageIgnoreStart
return RenameWiki::class;
// @codeCoverageIgnoreEnd
