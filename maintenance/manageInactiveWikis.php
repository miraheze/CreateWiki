<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use Miraheze\CreateWiki\RemoteWiki;

class ManageInactiveWikis extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addOption( 'write', 'Actually make changes to wikis which are considered for the next stage in dormancy', false, false );
		$this->addDescription( 'A script to find inactive wikis in a farm.' );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		if ( !$this->getConfig()->get( 'CreateWikiEnableManageInactiveWikis' ) ) {
			$this->fatalError(
				'This script can not be run because it has not yet been enabled. You may enable $wgCreateWikiEnableManageInactiveWikis in order to run this script.'
			);
		}

		$hookRunner = $this->getServiceContainer()->get( 'CreateWikiHookRunner' );

		$dbr = $this->getDB( DB_REPLICA, [], $this->getConfig()->get( 'CreateWikiDatabase' ) );

		$res = $dbr->select(
			'cw_wikis',
			'wiki_dbname',
			[
				'wiki_inactive_exempt' => 0,
				'wiki_deleted' => 0
			],
			__METHOD__
		);

		foreach ( $res as $row ) {
			$dbName = $row->wiki_dbname;
			$wiki = new RemoteWiki( $dbName, $hookRunner );
			$inactiveDays = (int)$this->getConfig()->get( 'CreateWikiStateDays' )['inactive'];

			if ( $wiki->getCreationDate() < date( "YmdHis", strtotime( "-{$inactiveDays} days" ) ) ) {
				$this->checkLastActivity( $dbName, $wiki );
			}
		}
	}

	private function checkLastActivity( $dbName, $wiki ) {
		$inactiveDays = (int)$this->getConfig()->get( 'CreateWikiStateDays' )['inactive'];
		$closeDays = (int)$this->getConfig()->get( 'CreateWikiStateDays' )['closed'];
		$removeDays = (int)$this->getConfig()->get( 'CreateWikiStateDays' )['removed'];

		$canWrite = $this->hasOption( 'write' );

		/** @var CheckLastWikiActivity $activity */
		$activity = $this->runChild(
			CheckLastWikiActivity::class,
			MW_INSTALL_PATH . '/extensions/CreateWiki/maintenance/checkLastWikiActivity.php'
		);

		$activity->setOption( 'wiki', $dbName );
		$activity->setOption( 'quiet', true );
		$ok = $activity->execute();

		$timeStamp = $activity->timestamp;

		// If for some reason $timeStamp returns a non-numeric value, bail out.
		if ( !is_numeric( $timeStamp ) ) {
			return true;
		}

		// Wiki doesn't seem inactive: go on to the next wiki.
		if ( $timeStamp > date( "YmdHis", strtotime( "-{$inactiveDays} days" ) ) ) {
			if ( $canWrite && $wiki->isInactive() ) {
				$wiki->markActive();
				$wiki->commit();
			}

			return true;
		}

		if ( !$wiki->isClosed() ) {
			// Wiki is NOT closed yet
			$closeTime = $inactiveDays + $closeDays;

			if ( $timeStamp < date( "YmdHis", strtotime( "-{$closeTime} days" ) ) ) {
				// Last RC entry older than allowed time
				if ( $canWrite ) {
					$wiki->markClosed();
					$this->notify( $dbName );

					$this->output( "{$dbName} was eligible for closing and has been closed now. Last activity was at {$timeStamp}\n" );
				} else {
					$this->output( "{$dbName} should be closed. Timestamp of last recent changes entry: {$timeStamp}\n" );
				}
			} elseif ( $timeStamp < date( "YmdHis", strtotime( "-{$inactiveDays} days" ) ) ) {
				// Meets inactivity
				if ( $canWrite ) {
					$wiki->markInactive();

					$this->output( "{$dbName} was eligible for a warning notice and one was given. Last activity was at {$timeStamp}\n" );
				} else {
					$this->output( "{$dbName} should get a warning notice. Timestamp of last recent changes entry: {$timeStamp}\n" );
				}
			} else {
				// No RC entries
				if ( !$wiki->isInactive() ) {
					// Wiki not marked inactive yet, warning should be given
					if ( $canWrite ) {
						$wiki->markInactive();

						$this->output( "{$dbName} does not seem to contain recentchanges entries, therefore warning.\n" );
					} else {
						$this->output( "{$dbName} does not seem to contain recentchanges entries, eligible for warning.\n" );
					}
				} elseif ( $wiki->isInactive() && $wiki->isInactive() < date( "YmdHis", strtotime( "-{$closeDays} days" ) ) ) {
					// Wiki already warned, eligible for closure
					if ( $canWrite ) {
						$wiki->markClosed();
						$this->notify( $dbName );

						$this->output( "{$dbName} does not seem to contain recentchanges entries after {$closeDays}+ days warning, therefore closing.\n" );
					} else {
						$this->output( "{$dbName} does not seem to contain recentchanges entries after {$closeDays}+ days warning, eligible for closure.\n" );
					}
				} else {
					// Wiki warned recently
					$this->output( "{$dbName} does not seem to contain recentchanges entries, warned recently.\n" );
				}
			}
		} else {
			// Wiki already has been closed
			if ( $wiki->isClosed() && $wiki->isClosed() < date( "YmdHis", strtotime( "-{$removeDays} days" ) ) ) {
				// Wiki closed, eligible for deletion
				if ( $canWrite ) {
					$wiki->delete();

					$this->output( "{$dbName} is eligible to be removed from public viewing and has been.\n" );
				} else {
					$this->output( "{$dbName} is eligible for public removal, was closed on {$wiki->isClosed()}.\n" );
				}
			} elseif ( $wiki->isClosed() && $wiki->isClosed() > date( "YmdHis", strtotime( "-{$removeDays} days" ) ) ) {
				// Wiki closed but not yet eligible for removal
				$this->output( "{$dbName} is not eligible for public removal yet, but has already been closed on {$wiki->isClosed()}.\n" );
			} else {
				// Could not determine closure date, fallback
				$this->output( "{$dbName} has already been closed but its closure date could not be determined. Please check!\n" );
			}
		}

		$wiki->commit();

		return true;
	}

	private function notify( $wiki ) {
		$notificationData = [
			'type' => 'closure',
			'subject' => wfMessage( 'miraheze-close-email-subject', $wiki )->inContentLanguage()->text(),
			'body' => [
				'html' => wfMessage( 'miraheze-close-email-body' )->inContentLanguage()->text(),
				'text' => wfMessage( 'miraheze-close-email-body' )->inContentLanguage()->text(),
			],
		];

		$this->getServiceContainer()->get( 'CreateWiki.NotificationsManager' )
			->notifyBureaucrats( $notificationData, $wiki );
	}
}

$maintClass = ManageInactiveWikis::class;
require_once RUN_MAINTENANCE_IF_MAIN;
