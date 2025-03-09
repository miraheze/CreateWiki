<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Maintenance\Maintenance;

class RenameWiki extends Maintenance {

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

	public function execute(): void {
		$old = strtolower( $this->getOption( 'old' ) );
		$new = strtolower( $this->getOption( 'new' ) );

		if ( $this->hasOption( 'rename' ) ) {
			$this->output( "Renaming $old to $new. If this is wrong, Ctrl-C now!" );

			// let's count down JUST to be safe!
			$this->countDown( 10 );

			$wikiManagerFactory = $this->getServiceContainer()->get( 'WikiManagerFactory' );
			$wikiManager = $wikiManagerFactory->newInstance( $old );
			$rename = $wikiManager->rename( newDatabaseName: $new );

			if ( $rename ) {
				$this->fatalError( $rename );
			}
		} else {
			$this->output( "Wiki $old will be renamed to $new" );
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

			$this->getServiceContainer()->get( 'CreateWikiNotificationsManager' )
				->sendNotification(
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
