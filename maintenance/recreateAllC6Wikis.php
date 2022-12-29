<?php

use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\RemoteWiki;
use Miraheze\CreateWiki\WikiManager;
use Miraheze\ManageWiki\Helpers\ManageWikiExtensions;
use Miraheze\ManageWiki\Helpers\ManageWikiSettings;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../..';
}

require_once "$IP/maintenance/Maintenance.php";

class RecreateAllC6Wikis extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'dry-run', 'Do not actually recreate the wikis, just show which ones would be recreated' );
		$this->addOption( 'actor', 'Username of the actor that will be used to recreate the wikis', true, true );
		$this->setBatchSize( 1 );
		$this->requireExtension( 'CreateWiki' );
		$this->requireExtension( 'ManageWiki' );
	}

	public function execute() {
		$dryRun = $this->getOption( 'dry-run', false );

		// get the main load balancer
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();

		// get the connection to the mhglobal database
		$dbw = $lb->getConnection( DB_PRIMARY, [], 'mhglobal' );

		// get the connection to the metawiki database
		$dbr = $lb->getConnection( DB_REPLICA, [], 'metawiki' );

		// select all wikis in the c6 cluster from the cw_wikis table
		$res = $dbw->select(
			'cw_wikis',
			[ 'wiki_dbname' ],
			[ 'wiki_dbcluster' => 'c6' ]
		);

		foreach ( $res as $row ) {
			$wikiDBname = $row->wiki_dbname;

			// get the most recent request for this wiki from the cw_requests table
			$request = $dbr->selectRow(
				'cw_requests',
				[ '*' ],
				[ 'cw_dbname' => $wikiDBname ],
				__METHOD__,
				[ 'ORDER BY' => 'cw_id DESC' ]
			);

			if ( !$dryRun ) {
				// create a new instance of the ManageWikiSettings class
				$manageWikiSettings = new ManageWikiSettings( $wikiDBname );

				// create a new instance of the ManageWikiExtensions class
				$manageWikiExtensions = new ManageWikiExtensions( $wikiDBname );

				// list current settings
				$currentSettings = $manageWikiSettings->list();

				// list current extensions
				$currentExtensions = $manageWikiExtensions->list();

				// delete the rows from the cw_wikis, localnames, localuser, mw_settings, and matomo tables
				$dbw->delete(
					'cw_wikis',
					[ 'wiki_dbname' => $wikiDBname ],
					__METHOD__
				);

				$dbw->delete(
					'localnames',
					[ 'ln_wiki' => $wikiDBname ],
					__METHOD__
				);

				$dbw->delete(
					'localuser',
					[ 'lu_wiki' => $wikiDBname ],
					__METHOD__
				);

				$dbw->delete(
					'mw_settings',
					[ 's_dbname' => $wikiDBname ],
					__METHOD__
				);

				$dbw->delete(
					'matomo',
					[ 'matomo_wiki' => $wikiDBname ],
					__METHOD__
				);

				// create a new instance of the WikiManager class
				$wikiManager = new WikiManager( $wikiDBname );

				// recreate the wiki using the data from the cw_requests table
				$wikiManager->create(
					$request->cw_sitename,
					$request->cw_language,
					$request->cw_private,
					$request->cw_category,
					User::newFromId( $request->cw_user )->getName(),
					$this->getOption( 'actor' ),
					$request->cw_comment
				);

				// create a new instance of the ManageWikiSettings class
				$manageWikiSettings = new ManageWikiSettings( $wikiDBname );

				// re-apply settings
				$manageWikiSettings->overwriteAll( $currentSettings );
				$manageWikiSettings->commit();

				// create a new instance of the ManageWikiExtensions class
				$manageWikiExtensions = new ManageWikiExtensions( $wikiDBname );

				// re-apply extensions
				$manageWikiExtensions->overwriteAll( $currentExtensions );
				$manageWikiExtensions->commit();

				$remoteWiki = new RemoteWiki( $wikiDBname );
				if ( $row->wiki_inactive_exempt ) {
					// re-apply inactive exempt status
					$remoteWiki->markExempt();
				}

				if ( $row->wiki_experimental ) {
					// re-apply experimental status
					$remoteWiki->markExperimental();
				}

				if ( $row->wiki_inactive ) {
					// re-apply inactive status
					$remoteWiki->markInactive();
				}

				if ( $row->wiki_closed ) {
					// re-apply closed status
					$remoteWiki->markClosed();
				}

				if ( $row->wiki_locked ) {
					// re-apply locked status
					$remoteWiki->lock();
				}

				if ( $row->wiki_deleted ) {
					// re-apply deleted status
					$remoteWiki->delete();
				}

				// commit changes
				$remoteWiki->commit();
			} else {
				$this->output( "Would recreate $wikiDBname using data from cw_requests table\n" );
			}
		}
	}
}

$maintClass = RecreateAllC6Wikis::class;
require_once RUN_MAINTENANCE_IF_MAIN;
