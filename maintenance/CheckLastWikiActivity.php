<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Rdbms\SelectQueryBuilder;
use function max;
use const DB_REPLICA;

class CheckLastWikiActivity extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Calculates the timestamp of the last meaningful contribution to the wiki.' );
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$this->output( "{$this->getTimestamp()}\n" );
	}

	public function getTimestamp(): int {
		$dbr = $this->getDB( DB_REPLICA );

		// Get the latest revision timestamp
		$revTimestamp = $dbr->newSelectQueryBuilder()
			->select( 'rev_timestamp' )
			->from( 'revision' )
			->orderBy( 'rev_timestamp', SelectQueryBuilder::SORT_DESC )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchField();

		// Get the latest logging timestamp
		$logTimestamp = $dbr->newSelectQueryBuilder()
			->select( 'log_timestamp' )
			->from( 'logging' )
			->where( [
				$dbr->expr( 'log_type', '!=', 'renameuser' ),
				$dbr->expr( 'log_type', '!=', 'newusers' ),
			] )
			->orderBy( 'log_timestamp', SelectQueryBuilder::SORT_DESC )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchField();

		// Return the most recent timestamp in either revision or logging
		return (int)max( $revTimestamp, $logTimestamp );
	}
}

// @codeCoverageIgnoreStart
return CheckLastWikiActivity::class;
// @codeCoverageIgnoreEnd
