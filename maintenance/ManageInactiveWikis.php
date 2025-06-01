<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Helpers\RemoteWiki;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\CreateWikiDataFactory;
use Miraheze\CreateWiki\Services\CreateWikiNotificationsManager;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use function date;
use function strtotime;
use function wfMessage;
use const DB_PRIMARY;

/**
 * Maintenance script for marking wikis as inactive, closed, and deleted
 * based on the values set in $wgCreateWikiStateDays.
 *
 * @author Universal Omega
 */
class ManageInactiveWikis extends Maintenance {

	private CreateWikiDatabaseUtils $databaseUtils;
	private CreateWikiDataFactory $dataFactory;
	private CreateWikiNotificationsManager $notificationsManager;
	private RemoteWikiFactory $remoteWikiFactory;

	public function __construct() {
		parent::__construct();

		$this->addOption( 'write',
			'Make changes to wikis that are eligible for the next stage in $wgCreateWikiStateDays.',
			false, false
		);

		$this->addDescription( 'Script to manage inactive wikis in a wiki farm.' );
		$this->requireExtension( 'CreateWiki' );
	}

	private function initServices(): void {
		$services = $this->getServiceContainer();
		$this->databaseUtils = $services->get( 'CreateWikiDatabaseUtils' );
		$this->dataFactory = $services->get( 'CreateWikiDataFactory' );
		$this->notificationsManager = $services->get( 'CreateWikiNotificationsManager' );
		$this->remoteWikiFactory = $services->get( 'RemoteWikiFactory' );
	}

	public function execute(): void {
		if ( !$this->getConfig()->get( ConfigNames::EnableManageInactiveWikis ) ) {
			$this->fatalError( 'Enable $wgCreateWikiEnableManageInactiveWikis to run this script.' );
		}

		$this->initServices();
		$dbr = $this->databaseUtils->getGlobalReplicaDB();

		$wikis = $dbr->newSelectQueryBuilder()
			->select( 'wiki_dbname' )
			->from( 'cw_wikis' )
			->where( [
				'wiki_inactive_exempt' => 0,
				'wiki_deleted' => 0,
			] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		foreach ( $wikis as $wiki ) {
			$remoteWiki = $this->remoteWikiFactory->newInstance( $wiki );
			$inactiveDays = (int)$this->getConfig()->get( ConfigNames::StateDays )['inactive'];

			$remoteWiki->disableResetDatabaseLists();

			// Check if the wiki is inactive based on creation date
			if ( $remoteWiki->getCreationDate() < date( 'YmdHis', strtotime( "-{$inactiveDays} days" ) ) ) {
				$this->checkLastActivity( $wiki, $remoteWiki );
			}
		}

		$data = $this->dataFactory->newInstance( $this->databaseUtils->getCentralWikiID() );
		$data->resetDatabaseLists( isNewChanges: true );
	}

	private function checkLastActivity(
		string $dbname,
		RemoteWiki $remoteWiki
	): bool {
		$inactiveDays = (int)$this->getConfig()->get( ConfigNames::StateDays )['inactive'];
		$closeDays = (int)$this->getConfig()->get( ConfigNames::StateDays )['closed'];
		$removeDays = (int)$this->getConfig()->get( ConfigNames::StateDays )['removed'];
		$canWrite = $this->hasOption( 'write' );

		/** @var CheckLastWikiActivity $activity */
		$activity = $this->createChild( CheckLastWikiActivity::class );
		'@phan-var CheckLastWikiActivity $activity';

		$activity->setDB( $this->getDB( DB_PRIMARY, [], $dbname ) );
		$lastActivityTimestamp = $activity->getTimestamp();

		// If the wiki is still active, mark it as active
		if ( $lastActivityTimestamp > date( 'YmdHis', strtotime( "-{$inactiveDays} days" ) ) ) {
			if ( $canWrite && $remoteWiki->isInactive() ) {
				$remoteWiki->markActive();
				$remoteWiki->commit();

				$this->output( "{$dbname} has been marked as active.\n" );
			}

			return true;
		}

		// If the wiki is not closed yet
		if ( !$remoteWiki->isClosed() ) {
			$closeTime = $inactiveDays + $closeDays;

			if ( $lastActivityTimestamp < date( 'YmdHis', strtotime( "-{$closeTime} days" ) ) ) {
				if ( $canWrite ) {
					$remoteWiki->markClosed();
					$this->notifyBureaucrats( $dbname );
					$this->output( "{$dbname} has been closed. Last activity: {$lastActivityTimestamp}\n" );
				} else {
					$this->output( "{$dbname} should be closed. Last activity: {$lastActivityTimestamp}\n" );
				}
			} else {
				if (
					!$remoteWiki->isInactive() &&
					$lastActivityTimestamp < date( 'YmdHis', strtotime( "-{$inactiveDays} days" ) )
				) {
					// Meets inactivity
					if ( $canWrite ) {
						$remoteWiki->markInactive();
						$this->output( "{$dbname} was marked as inactive. Last activity: {$lastActivityTimestamp}\n" );
					} else {
						$this->output( "{$dbname} should be inactive. Last activity: {$lastActivityTimestamp}\n" );
					}
				} else {
					// Otherwise, mark as closed or notify if it's eligible for closure
					$this->handleInactiveWiki(
						$dbname,
						$remoteWiki,
						$closeDays,
						$lastActivityTimestamp,
						$canWrite
					);
				}
			}
		} else {
			// Handle already closed wikis
			$this->handleClosedWiki(
				$dbname,
				$remoteWiki,
				$removeDays,
				$lastActivityTimestamp,
				$canWrite
			);
		}

		$remoteWiki->commit();
		return true;
	}

	private function handleInactiveWiki(
		string $dbname,
		RemoteWiki $remoteWiki,
		int $closeDays,
		int $lastActivityTimestamp,
		bool $canWrite
	): void {
		$inactiveTimestamp = $remoteWiki->getInactiveTimestamp();
		$isInactive = $remoteWiki->isInactive() && $inactiveTimestamp;
		if ( $isInactive && $inactiveTimestamp < date( 'YmdHis', strtotime( "-{$closeDays} days" ) ) ) {
			if ( $canWrite ) {
				$remoteWiki->markClosed();
				$this->notifyBureaucrats( $dbname );
				$this->output(
					"{$dbname} was marked as inactive on {$inactiveTimestamp} and is now closed. " .
					"Last activity: {$lastActivityTimestamp}.\n"
				);
			} else {
				$this->output(
					"{$dbname} was marked as inactive on {$inactiveTimestamp} and should be closed. " .
					"Last activity: {$lastActivityTimestamp}.\n"
				);
			}
		} elseif ( $isInactive ) {
			$this->output(
				"{$dbname} was marked as inactive on {$inactiveTimestamp} " .
				"but is not yet eligible for closure. Last activity: {$lastActivityTimestamp}.\n"
			);
		}
	}

	private function handleClosedWiki(
		string $dbname,
		RemoteWiki $remoteWiki,
		int $removeDays,
		int $lastActivityTimestamp,
		bool $canWrite
	): void {
		$closedTimestamp = $remoteWiki->getClosedTimestamp();
		$isClosed = $remoteWiki->isClosed() && $closedTimestamp;
		if ( $isClosed && $closedTimestamp < date( 'YmdHis', strtotime( "-{$removeDays} days" ) ) ) {
			if ( $canWrite ) {
				$remoteWiki->delete();
				$this->output(
					"{$dbname} is eligible for removal and now has been. " .
					"It was closed on {$closedTimestamp}. Last activity: {$lastActivityTimestamp}.\n"
				);
			} else {
				$this->output(
					"{$dbname} is eligible for removal if --write is used. " .
					"It was closed on {$closedTimestamp}. Last activity: {$lastActivityTimestamp}.\n"
				);
			}
		} else {
			$this->output(
				"{$dbname} was closed on {$closedTimestamp} but is not yet eligible for deletion. " .
				"It may have been manually closed. Last activity: {$lastActivityTimestamp}.\n"
			);
		}
	}

	private function notifyBureaucrats( string $dbname ): void {
		$notificationData = [
			'type' => 'closure',
			'subject' => wfMessage( 'createwiki-close-email-subject', $dbname )->inContentLanguage()->text(),
			'body' => [
				'html' => wfMessage( 'createwiki-close-email-body' )->inContentLanguage()->parse(),
				'text' => wfMessage( 'createwiki-close-email-body' )->inContentLanguage()->text(),
			],
		];

		$this->notificationsManager->notifyBureaucrats( $notificationData, $dbname );
	}
}

// @codeCoverageIgnoreStart
return ManageInactiveWikis::class;
// @codeCoverageIgnoreEnd
