<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Maintenance\Maintenance;

class DeleteWikis extends Maintenance {

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

	public function execute(): void {
		$user = $this->getOption( 'user' );
		if ( !$user ) {
			$this->fatalError( 'Please specify the username of the user executing this script.' );
		}

		$deletedWikis = [];

		try {
			// Single deletion mode
			$dbname = $this->getOption( 'deletewiki' );
			if ( $dbname ) {
				$deletedWikis[] = $dbname;
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
						// We don't use fatalError here since that calls
						// exit() which would stop the finally block from
						// executing and we always want it to execute.
						$this->output( "$delete\n" );
						return;
					}

					$this->output( "Wiki $dbname deleted.\n" );
				} else {
					$this->output( "Wiki $dbname would be deleted. Use --delete to actually perform deletion.\n" );
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

				$deletedWikis[] = $wiki;
				if ( $this->hasOption( 'delete' ) ) {
					$wikiManager = $wikiManagerFactory->newInstance( $wiki );
					$delete = $wikiManager->delete( force: false );

					if ( $delete ) {
						$this->output( "{$wiki}: {$delete}\n" );
						continue;
					}

					$this->output( "$dbCluster: DROP DATABASE {$wiki};\n" );
				} else {
					$this->output( "$wiki: $dbCluster\n" );
				}
			}

			$this->output( "Done.\n" );
		} finally {
			// Make sure we notify deletions regardless even
			// if an exception occurred, we always want to notify which
			// ones have already been deleted.
			$this->notifyDeletions( $user, $deletedWikis );
		}
	}

	/**
	 * Sends a notification about the deletion(s) performed.
	 *
	 * @param string $user The username that initiated the deletion.
	 * @param array $deletedWikis List of wiki names that were deleted or would be deleted.
	 */
	private function notifyDeletions( string $user, array $deletedWikis ): void {
		$deletedWikisList = implode( ', ', $deletedWikis );
		$action = $this->hasOption( 'delete' ) ? 'has deleted' : 'is about to delete';

		$message = "Hello!\nThis is an automatic notification from CreateWiki notifying you that " .
			"just now that $user $action the following wiki(s) from CreateWiki and " .
			"associated extensions:\n{$deletedWikisList}";

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
	}
}

// @codeCoverageIgnoreStart
return DeleteWikis::class;
// @codeCoverageIgnoreEnd
