<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\CreateWikiNotificationsManager;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use stdClass;
use function implode;

class DeleteWikis extends Maintenance {

	private CreateWikiDatabaseUtils $databaseUtils;
	private CreateWikiNotificationsManager $notificationsManager;
	private WikiManagerFactory $wikiManagerFactory;

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

	private function initServices(): void {
		$services = $this->getServiceContainer();
		$this->databaseUtils = $services->get( 'CreateWikiDatabaseUtils' );
		$this->notificationsManager = $services->get( 'CreateWikiNotificationsManager' );
		$this->wikiManagerFactory = $services->get( 'WikiManagerFactory' );
	}

	public function execute(): void {
		$this->initServices();
		$dbr = $this->databaseUtils->getGlobalReplicaDB();

		$res = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_deleted' => 1 ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$deletedWikis = [];

		foreach ( $res as $row ) {
			if ( !$row instanceof stdClass ) {
				// Skip unexpected row
				continue;
			}

			$dbname = $row->wiki_dbname;
			$dbCluster = $row->wiki_dbcluster;

			if ( $this->hasOption( 'delete' ) ) {
				$wikiManager = $this->wikiManagerFactory->newInstance( $dbname );
				$delete = $wikiManager->delete( force: false );

				if ( $delete ) {
					$this->output( "$dbname: $delete\n" );
					continue;
				}

				$this->output( "$dbCluster: DROP DATABASE $dbname;\n" );
				$deletedWikis[] = $dbname;
			} else {
				$this->output( "$dbname: $dbCluster\n" );
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

		$this->notificationsManager->sendNotification(
			data: $notificationData,
			// No receivers, it will send to configured email
			receivers: []
		);
	}
}

// @codeCoverageIgnoreStart
return DeleteWikis::class;
// @codeCoverageIgnoreEnd
