<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Maintenance\Maintenance;

class DeleteWikis extends Maintenance {

	private array $deletedWikis = [];
	private bool $notified = false;

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Deletes wikis. If the --deletewiki option is provided, deletes a single wiki specified by database name. ' .
			'Otherwise, lists or deletes all wikis marked as deleted (will never DROP a database!). ' .
			'A notification is always sent regardless of mode.'
		);

		$this->addOption( 'deletewiki', 'Specify the database name to delete (single deletion mode).', false, true );
		$this->addOption( 'delete', 'Actually performs deletion and not just outputs what would be deleted.' );

		$this->addOption( 'user',
			'Username or reference name of the person running this script. ' .
			'Will be used in tracking and notification internally.',
		true, true );

		$this->requireExtension( 'CreateWiki' );
	}

	private function log( $msg ) {
		$logger = $this->getServiceContainer()->get( 'CreateWikiLogger' );
		$logger->debug( "DeleteWikis: $msg" );
		$this->output( "$msg\n" );
	}

	public function execute(): void {
		$user = $this->getOption( 'user' );
		if ( !$user ) {
			$this->fatalError( 'Please specify the username of the user executing this script.' );
		}

		$this->deletedWikis = [];

		register_shutdown_function( [ $this, 'shutdownHandler' ] );

		try {
			// Single deletion mode
			$dbname = $this->getOption( 'deletewiki' );
			if ( $dbname ) {
				if ( $this->hasOption( 'delete' ) ) {
					$this->output(
						"You are about to delete $dbname from CreateWiki. " .
						"This will not DROP the database. If this is wrong, Ctrl-C now!\n"
					);

					// let's count down JUST to be safe!
					$this->countDown( 10 );

					$wikiManager = $this->getServiceContainer()->get( 'WikiManagerFactory' )
						->newInstance( $dbname );
					$delete = $wikiManager->delete( force: true );

					if ( $delete ) {
						$this->fatalError( $delete );
					}

					$this->log( "Wiki $dbname deleted.\n" );
					$this->deletedWikis[] = $dbname;
				} else {
					$this->output( "Wiki $dbname would be deleted. Use --delete to actually perform deletion.\n" );
					$this->deletedWikis[] = $dbname;
				}

				return;
			}

			// Multi deletion mode
			if ( $this->hasOption( 'delete' ) ) {
				$this->output(
					"You are about to delete all wikis that are marked as deleted from CreateWiki. " .
					"This will not DROP any databases. If this is wrong, Ctrl-C now!\n"
				);

				// let's count down JUST to be safe!
				$this->countDown( 10 );
			}

			$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
			$wikiManagerFactory = $this->getServiceContainer()->get( 'WikiManagerFactory' );
			$dbr = $databaseUtils->getGlobalReplicaDB();

			$res = $dbr->newSelectQueryBuilder()
				->table( 'cw_wikis' )
				->fields( [
					'wiki_dbcluster',
					'wiki_dbname',
				] )
				->where( [ 'wiki_deleted' => 1 ] )
				->caller( __METHOD__ )
				->fetchResultSet();

			foreach ( $res as $row ) {
				$wiki = $row->wiki_dbname;
				$dbCluster = $row->wiki_dbcluster;

				if ( $this->hasOption( 'delete' ) ) {
					$wikiManager = $wikiManagerFactory->newInstance( $wiki );
					$delete = $wikiManager->delete( force: false );

					if ( $delete ) {
						$this->log( "{$wiki}: {$delete}\n" );
						continue;
					}

					$this->log( "$dbCluster: DROP DATABASE {$wiki};\n" );
					$this->deletedWikis[] = $wiki;
				} else {
					$this->output( "$wiki: $dbCluster\n" );
					$this->deletedWikis[] = $wiki;
				}
			}

			$this->output( "Done.\n" );
		} finally {
			// Make sure we notify deletions regardless even
			// if an exception occurred, we always want to notify which
			// ones have already been deleted.
			$this->notifyDeletions();
		}
	}

	/**
	 * Shutdown handler to catch termination of the script.
	 */
	public function shutdownHandler(): void {
		if ( !$this->notified ) {
			$this->notifyDeletions();
		}
	}

	private function notifyDeletions(): void {
		$user = $this->getOption( 'user' );
		$deletedWikisList = implode( ', ', $this->deletedWikis );
		$action = $this->hasOption( 'delete' ) ? 'has deleted' : 'is about to delete';

		$message = "Hello!\nThis is an automatic notification from CreateWiki notifying you that " .
			"just now that $user $action the following wiki(s) from CreateWiki and " .
			"associated extensions:\n$deletedWikisList";

		$notificationData = [
			'type' => 'deletion',
			'subject' => 'Wikis Deleted Notification',
			'body' => $message,
		];

		$this->getServiceContainer()->get( 'CreateWikiNotificationsManager' )
			->sendNotification(
				data: $notificationData,
				// No receivers, it will send to configured email
				receivers: []
			);

		$this->notified = true;
	}
}

// @codeCoverageIgnoreStart
return DeleteWikis::class;
// @codeCoverageIgnoreEnd
