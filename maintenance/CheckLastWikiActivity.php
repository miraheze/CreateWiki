<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Maintenance\Maintenance;

class CheckLastWikiActivity extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Calculates the timestamp of the last meaningful contribution to the wiki.' );
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		if ( !$this->isQuiet() ) {
			$this->output( (string)$this->getTimestamp() );
		}
	}

	public function getTimestamp(): int {
		$dbr = $this->getDB( DB_REPLICA );

		// Get the latest revision timestamp
		$revTimestamp = $dbr->newSelectQueryBuilder()
			->select( 'MAX(rev_timestamp)' )
			->from( 'revision' )
			->caller( __METHOD__ )
			->fetchField();

		// Get the latest logging timestamp
		$logTimestamp = $dbr->newSelectQueryBuilder()
			->select( 'MAX(log_timestamp)' )
			->from( 'logging' )
			->where( [
				$dbr->expr( 'log_type', '!=', 'renameuser' ),
				$dbr->expr( 'log_type', '!=', 'newusers' ),
			] )
			->caller( __METHOD__ )
			->fetchField();

		// Return the most recent timestamp in either revision or logging
		return (int)max( $revTimestamp, $logTimestamp );
	}
}

// @codeCoverageIgnoreStart
return CheckLastWikiActivity::class;
// @codeCoverageIgnoreEnd
