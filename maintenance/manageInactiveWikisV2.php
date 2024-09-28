<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;

/**
 * This script is an experimental version of ManageInactiveWikis
 * It is designed to be a more readable/understandable and reliable version.
 *
 * @author Universal Omega
 */

class ManageInactiveWikisV2 extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addOption( 'write', 'Make changes to wikis that are eligible for the next stage in $wgCreateWikiStateDays.', false, false );
		$this->addDescription( 'Script to manage inactive wikis in a wiki farm.' );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		if ( !$this->getConfig()->get( 'CreateWikiEnableManageInactiveWikis' ) ) {
			$this->fatalError( 'Enable $wgCreateWikiEnableManageInactiveWikis to run this script.' );
		}

		$remoteWikiFactory = $this->getServiceContainer()->get( 'RemoteWikiFactory' );
		$dbr = $this->getDB( DB_REPLICA, [], $this->getConfig()->get( 'CreateWikiDatabase' ) );

		$res = $dbr->select(
			'cw_wikis',
			'wiki_dbname',
			[
				'wiki_inactive_exempt' => 0,
				'wiki_deleted' => 0,
			],
			__METHOD__
		);

		foreach ( $res as $row ) {
			$dbName = $row->wiki_dbname;
			$remoteWiki = $remoteWikiFactory->newInstance( $dbName );
			$inactiveDays = (int)$this->getConfig()->get( 'CreateWikiStateDays' )['inactive'];

			// Check if the wiki is inactive based on creation date
			if ( $wiki->getCreationDate() < date( 'YmdHis', strtotime( "-{$inactiveDays} days" ) ) ) {
				$this->checkLastActivity( $dbName, $remoteWiki );
			}
		}
	}

	private function checkLastActivity(
		string $dbName,
		RemoteWikiFactory $remoteWiki
	): bool {
		$inactiveDays = (int)$this->getConfig()->get( 'CreateWikiStateDays' )['inactive'];
		$closeDays = (int)$this->getConfig()->get( 'CreateWikiStateDays' )['closed'];
		$removeDays = (int)$this->getConfig()->get( 'CreateWikiStateDays' )['removed'];
		$canWrite = $this->hasOption( 'write' );

		/** @var CheckLastWikiActivity $activity */
		$activity = $this->runChild(
			CheckLastWikiActivity::class,
			MW_INSTALL_PATH . '/extensions/CreateWiki/maintenance/checkLastWikiActivity.php'
		);
		'@phan-var CheckLastWikiActivity $activity';

		$activity->loadParamsAndArgs( null, [ 'quiet' => true ] );
		$activity->setDB( $this->getDB( DB_PRIMARY, [], $dbName ) );
		$activity->execute();

		$lastActivityTimestamp = $activity->timestamp;

		// If the wiki is still active, mark it as active
		if ( $lastActivityTimestamp > date( 'YmdHis', strtotime( "-{$inactiveDays} days" ) ) ) {
			if ( $canWrite && $remoteWiki->isInactive() ) {
				$remoteWiki->markActive();
				$remoteWiki->commit();
			}

			return true;
		}

		// If the wiki is not closed yet
		if ( !$remoteWiki->isClosed() ) {
			$closeTime = $inactiveDays + $closeDays;

			// If the wiki is inactive and the inactive timestamp is older than close days
			$isInactive = $remoteWiki->isInactive() && $remoteWiki->getInactiveTimestamp();
			if ( $isInactive && $remoteWiki->getInactiveTimestamp() < date( 'YmdHis', strtotime( "-{$closeDays} days" ) ) ) {
				if ( $canWrite ) {
					$remoteWiki->markClosed();
					$this->notify( $dbName );
					$this->output( "{$dbName} has been closed. Last activity: {$lastActivityTimestamp}\n" );
				} else {
					$this->output( "{$dbName} should be closed. Last activity: {$lastActivityTimestamp}\n" );
				}
			} else {
				// Otherwise, mark as inactive or notify if it's eligible for closure
				$this->handleInactiveWiki( $dbName, $remoteWiki, $closeDays, $canWrite );
			}
		} else {
			// Handle already closed wikis
			$this->handleClosedWiki( $dbName, $remoteWiki, $removeDays, $canWrite );
		}

		$wiki->commit();
		return true;
	}

	private function handleInactiveWiki(
		string $dbName,
		RemoteWikiFactory $remoteWiki,
		int $closeDays,
		bool $canWrite
	): void {
		$isInactive = $remoteWiki->isInactive() && $remoteWiki->getInactiveTimestamp();
		if ( $isInactive && $remoteWiki->getInactiveTimestamp() < date( 'YmdHis', strtotime( "-{$closeDays} days" ) ) ) {
			if ( $canWrite ) {
				$remoteWiki->markClosed();
				$this->notify( $dbName );
				$this->output( "{$dbName} was inactive and is now closed.\n" );
			} else {
				$this->output( "{$dbName} is inactive and should be closed.\n" );
			}
		} else {
			$this->output( "{$dbName} remains inactive but is not yet eligible for closure.\n" );
		}
	}

	private function handleClosedWiki(
		string $dbName,
		RemoteWikiFactory $remoteWiki,
		int $removeDays,
		bool $canWrite
	): void {
		$isClosed = $remoteWiki->isClosed() && $remoteWiki->getClosedTimestamp();
		if ( $isClosed && $remoteWiki->getClosedTimestamp() < date( 'YmdHis', strtotime( "-{$removeDays} days" ) ) ) {
			if ( $canWrite ) {
				// $remoteWiki->delete( force: false );
				// $this->output( "{$dbName} is eligible to be removed from public viewing and has been.\n" );
				$this->output( "{$dbName} is eligible for removal, but deletion is currently disabled.\n" );
			} else {
				// $this->output( "{$dbName} is eligible for public removal, was closed on {$remoteWiki->getClosedTimestamp()}.\n" );
				$this->output( "{$dbName} is eligible for removal if --write is used, but deletion is disabled.\n" );
			}
		} else {
			$this->output( "{$dbName} is closed but not yet eligible for deletion. It may have been manually closed.\n" );
		}
	}

	private function notify( string $dbName ): void {
		$notificationData = [
			'type' => 'closure',
			'subject' => wfMessage( 'miraheze-close-email-subject', $dbName )->inContentLanguage()->text(),
			'body' => [
				'html' => wfMessage( 'miraheze-close-email-body' )->inContentLanguage()->text(),
				'text' => wfMessage( 'miraheze-close-email-body' )->inContentLanguage()->text(),
			],
		];

		$this->getServiceContainer()->get( 'CreateWiki.NotificationsManager' )
			->notifyBureaucrats( $notificationData, $dbName );
	}
}

$maintClass = ManageInactiveWikisV2::class;
require_once RUN_MAINTENANCE_IF_MAIN;
