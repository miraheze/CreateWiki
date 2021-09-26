<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;

class RenameWiki extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->mDescription = 'Renames a wiki from it\'s original name to a new name. Will NOT perform core database operations so run AFTER new database exists and while old one still exists.';
		$this->addOption( 'rename', 'Performs the rename. If not, will output rename information.', false );
		$this->addArg( 'oldwiki', 'Old wiki database name', true );
		$this->addArg( 'newwiki', 'New wiki database name', true );
		$this->addArg( 'user', 'Username or reference name of the person running this script. Will be used in tracking and notification internally.', true );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$oldwiki = $this->getArg( 0 );
		$newwiki = $this->getArg( 1 );

		$renamedWiki = [];

		if ( $this->hasOption( 'rename' ) ) {
				$this->output( "Renaming $oldwiki to $newwiki. If this is wrong, Ctrl-C now!" );

				// let's count down JUST to be safe!
				$this->countDown( 10 );

				$wm = new WikiManager( $oldwiki );

				$rename = $wm->rename( $newwiki );

				if ( $rename ) {
					$this->output( "{$rename}" );

					return;
				}

				$dbw = wfGetDB( DB_PRIMARY, [], $config->get( 'CreateWikiDatabase' ) );

				Hooks::run( 'CreateWikiRename', [ $dbw, $oldwiki, $newwiki ] );

				$renamedWiki[] = $oldwiki;
				$renamedWiki[] = $newwiki;
			} else {
				$this->output( "Wiki $oldwiki will be renamed to $newwiki" );
			}

		$this->output( "Done.\n" );

		if ( $this->hasOption( 'rename' ) ) {
			$this->notifyRename( $config->get( 'CreateWikiNotificationEmail' ), $config->get( 'PasswordSender' ), $renamedWiki, $this->getArg( 2 ) );
		}
	}

	private function notifyRename( $to, $from, $wikidata, $user ) {
		$from = new MailAddress( $from, 'CreateWiki Notifications' );
		$to = new MailAddress( $to, 'Server Administrators' );
		$wikirename = implode( ' to ', $wikidata );
		$body = "Hello!\nThis is an automatic notification from CreateWiki notifying you that just now $user has renamed the following wiki from CreateWiki and associated extensions - From $wikirename.";

		return UserMailer::send( $to, $from, 'Wiki Rename Notification', $body );
	}
}
$maintClass = RenameWiki::class;
require_once RUN_MAINTENANCE_IF_MAIN;
