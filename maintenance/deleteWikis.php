<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;

class DeleteWikis extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Allows complete deletion of wikis with args controlling deletion levels. Will never DROP a database!' );

		$this->addOption( 'delete', 'Actually performs deletions and not outputs wikis to be deleted', false );
		$this->addArg( 'user', 'Username or reference name of the person running this script. Will be used in tracking and notification internally.', true );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$wikiManagerFactory = $this->getServiceContainer()->get( 'WikiManagerFactory' );
		$dbr = $this->getDB( DB_REPLICA, [], $this->getConfig()->get( 'CreateWikiDatabase' ) );

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
			$dbCluster = $row->wiki_dbcluster;

			if ( $this->hasOption( 'delete' ) ) {
				$wm = $wikiManagerFactory->newInstance( $wiki );
				$delete = $wm->delete( force: false );

				if ( $delete ) {
					$this->output( "{$wiki}: {$delete}\n" );
					continue;
				}

				$this->output( "$dbCluster: DROP DATABASE {$wiki};\n" );
				$deletedWikis[] = $wiki;
			} else {
				$this->output( "$wiki: $dbCluster\n" );
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

		$this->getServiceContainer()->get( 'CreateWiki.NotificationsManager' )
			->sendNotification(
				data: $notificationData,
				// No receivers, it will send to configured email
				receivers: []
			);
	}
}

$maintClass = DeleteWikis::class;
require_once RUN_MAINTENANCE_IF_MAIN;
