<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;

class DeleteWikis extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->mDescription = 'Allows complete deletion of wikis with args controlling deletion levels. Will never DROP a database!';
		$this->addOption( 'delete', 'Actually performs deletions and not outputs wikis to be deleted', false );
		$this->addArg( 'user', 'Username or reference name of the person running this script. Will be used in tracking and notification internally.', true );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$dbr = wfGetDB( DB_REPLICA, [], $config->get( 'CreateWikiDatabase' ) );

		$res = $dbr->select(
			'cw_wikis',
			'*',
			[
				'wiki_deleted' => 1
			],
			__METHOD__
		);

		$deletedWikis = [];

		foreach ( $res as $row ) {
			$wiki = $row->wiki_dbname;

			if ( $this->hasOption( 'delete' ) ) {
				// @phan-suppress-next-line SecurityCheck-PathTraversal
				$wm = new WikiManager( $wiki );

				$delete = $wm->delete();

				if ( $delete ) {
					$this->output( "{$wiki}: {$delete}\n" );
					continue;
				}

				$this->output( "DROP DATABASE {$wiki};\n" );
				$deletedWikis[] = $wiki;
			} else {
				$this->output( "$wiki\n" );
			}
		}

		$this->output( "Done.\n" );

		$user = $this->getArg( 0 );
		$deletedWikis = implode( ', ', $deletedWikis );

		$notificationData = [
			'type' => 'deletion',
			'subject' => 'Wikis Deleted Notification',
			'body' => "Hello!\nThis is an automatic notification from CreateWiki notifying you that just now {$user} has deleted the following wikis from the CreateWiki and associated extensions:\n{$deletedWikis}",
		];

		MediaWikiServices::getInstance()->get( 'CreateWiki.NotificationsManager' )
			->sendNotification( $notificationData );
	}
}

$maintClass = DeleteWikis::class;
require_once RUN_MAINTENANCE_IF_MAIN;
