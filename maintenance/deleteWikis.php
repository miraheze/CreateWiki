<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\WikiManager;

class DeleteWikis extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Allows complete deletion of wikis with args controlling deletion levels. Will never DROP a database!' );

		$this->addOption( 'delete', 'Actually performs deletions and not outputs wikis to be deleted', false );
		$this->addArg( 'user', 'Username or reference name of the person running this script. Will be used in tracking and notification internally.', true );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );
		$dbr = $this->getDB( DB_REPLICA, [], $config->get( 'CreateWikiDatabase' ) );
		$hookRunner = MediaWikiServices::getInstance()->get( 'CreateWikiHookRunner' );

		$table = 'cw_wikis';
		$hookRunner->onCreateWikiGetDatabaseTable( $table );

		$res = $dbr->select(
			$table,
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
				$wm = new WikiManager( $wiki, $hookRunner );

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
