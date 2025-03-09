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

		$this->addOption( 'rename', 'Performs the rename. If not, will output rename information.', false );
		$this->addArg( 'oldwiki', 'Old wiki database name', true );
		$this->addArg( 'newwiki', 'New wiki database name', true );

		$this->addArg( 'user',
			'Username or reference name of the person running this script. ' .
			'Will be used in tracking and notification internally.',
		true );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$oldwiki = $this->getArg( 0 );
		$newwiki = $this->getArg( 1 );

		$wikiManagerFactory = $this->getServiceContainer()->get( 'WikiManagerFactory' );
		if ( !$wikiManagerFactory->newInstance( $newwiki )->exists() ) {
			$this->fatalError( "Cannot rename {$oldwiki} to {$newwiki} as {$newwiki} does not exist" );
		}

		if ( $this->hasOption( 'rename' ) ) {
			$this->output( "Renaming $oldwiki to $newwiki. If this is wrong, Ctrl-C now!" );

			// let's count down JUST to be safe!
			$this->countDown( 10 );

			$wikiManager = $wikiManagerFactory->newInstance( $oldwiki );
			$rename = $wikiManager->rename( newDatabaseName: $newwiki );

			if ( $rename ) {
				$this->fatalError( $rename );
			}
		} else {
			$this->output( "Wiki $oldwiki will be renamed to $newwiki\n" );
		}

		$this->output( "Done.\n" );

		if ( $this->hasOption( 'rename' ) ) {
			$user = $this->getArg( 2 );

			$message = "Hello!\nThis is an automatic notification from CreateWiki notifying you that " .
				"just now {$user} has renamed the following wiki from CreateWiki and " .
				"associated extensions - From {$oldwiki} to {$newwiki}.";

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
