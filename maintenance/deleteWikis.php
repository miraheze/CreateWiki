<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use Miraheze\CreateWiki\ConfigNames;

class DeleteWikis extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Allows complete deletion of wikis with args controlling ' .
			'deletion levels. Will never DROP a database!'
		);

		$this->addOption( 'delete', 'Actually performs deletions and not outputs wikis to be deleted', false );
		$this->addArg( 'user', 'Username or reference name of the person running this script. ' .
			'Will be used in tracking and notification internally.',
		true );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$wikiManagerFactory = $this->getServiceContainer()->get( 'WikiManagerFactory' );
		$dbr = $this->getDB( DB_REPLICA, [], $this->getConfig()->get( ConfigNames::Database ) );

		$res = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_deleted' => 1 ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$deletedWikis = [];

		foreach ( $res as $row ) {
			$wiki = $row->wiki_dbname;
			$dbCluster = $row->wiki_dbcluster;

			if ( $this->hasOption( 'delete' ) ) {
				$wikiManager = $wikiManagerFactory->newInstance( $wiki );
				$delete = $wikiManager->delete( force: false );

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

		$message = "Hello!\nThis is an automatic notification from CreateWiki notifying you that " .
			"just now {$user} has deleted the following wikis from the CreateWiki and " .
			"associated extensions:\n{$deletedWikis}";

		$notificationData = [
			'type' => 'deletion',
			'subject' => 'Wikis Deleted Notification',
			'body' => $message,
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
