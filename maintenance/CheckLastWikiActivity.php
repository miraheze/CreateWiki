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

		$query = $dbr->newSelectQueryBuilder()
			->select(
				'GREATEST(
					(SELECT MAX(rev_timestamp) FROM ' . $dbr->tableName( 'revision' ) . '),
					(
     						SELECT MAX(log_timestamp) FROM ' . $dbr->tableName( 'logging' ) .
						' WHERE log_type NOT IN ("renameuser", "newusers")
					)
				) AS latest'
			)
			->caller( __METHOD__ )
			->fetchField();

		return (int)( $query ?? 0 );
	}
}

// @codeCoverageIgnoreStart
return CheckLastWikiActivity::class;
// @codeCoverageIgnoreEnd
