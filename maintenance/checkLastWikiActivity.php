<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use MediaWiki\SiteStats\SiteStats;
use RebuildRecentchanges;

class CheckLastWikiActivity extends Maintenance {

	public int $timestamp;

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Calculates the timestamp of the last meaningful contribution to the wiki.' );
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$timestamp = $this->getTimestamp();
		if ( $timestamp === 0 && SiteStats::edits() >= 2 ) {
			$rebuildRC = $this->runChild(
				RebuildRecentchanges::class,
				MW_INSTALL_PATH . '/maintenance/rebuildrecentchanges.php'
			);
			$rebuildRC->execute();
			$timestamp = $this->getTimestamp();
		}
		$this->timestamp = $timestamp;

		if ( !$this->isQuiet() ) {
			$this->output( (string)$this->timestamp );
		}
	}

	private function getTimestamp(): int {
		$dbr = $this->getReplicaDB();
		$timestamp = $dbr->newSelectQueryBuilder()
			->select( 'MAX(rc_timestamp)' )
			->from( 'recentchanges' )
			->where( [
				$dbr->expr( 'rc_log_type', '!=', 'renameuser' ),
				$dbr->expr( 'rc_log_type', '!=', 'newusers' ),
			] )
			->caller( __METHOD__ )
			->fetchField();

		return (int)$timestamp;
	}
}

$maintClass = CheckLastWikiActivity::class;
require_once RUN_MAINTENANCE_IF_MAIN;
