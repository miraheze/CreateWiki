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

	public $timestamp;

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Calculates the timestamp of the last meaningful contribution to the wiki.' );
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$timestamp = $this->getTimestamp();
		if ( $timestamp === 0 && SiteStats::edits() >= 2 ) {
			$this->setDB( $this->getDB( DB_PRIMARY ) );
			$rebuildRC = $this->runChild(
				RebuildRecentchanges::class,
				MW_INSTALL_PATH . '/maintenance/rebuildrecentchanges.php'
			);
			$rebuildRC->setDB( $this->getDB( DB_PRIMARY ) );
			$rebuildRC->execute();
			$this->->setDB( $this->getDB( DB_REPLICA ) );
			$timestamp = $this->getTimestamp();
		}
		$this->timestamp = $timestamp;

		if ( !$this->isQuiet() ) {
			$this->output( (string)$this->timestamp );
		}
	}

	private function getTimestamp(): int {
		$dbr = $this->getDB( DB_REPLICA );
		$row = $dbr->selectRow(
			'recentchanges',
			'rc_timestamp',
			[
				"rc_log_action != 'renameuser'",
				"rc_log_action != 'newusers'"
			],
			__METHOD__,
			[
				'ORDER BY' => 'rc_timestamp DESC'
			]
		);

		return $row ? $row->rc_timestamp : 0;
	}
}

$maintClass = CheckLastWikiActivity::class;
require_once RUN_MAINTENANCE_IF_MAIN;
