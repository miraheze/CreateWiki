<?php

namespace Miraheze\CreateWiki\Installer;

use MediaWiki\Installer\Task\Task;
use MediaWiki\MainConfigNames;
use MediaWiki\Status\Status;
use Wikimedia\Rdbms\ChangedTablesTracker;
use Wikimedia\Rdbms\Platform\SQLPlatform;
use Wikimedia\Rdbms\Query;

/** @codeCoverageIgnore Tested by installing MediaWiki. */
class PopulateCentralWikiTask extends Task {

	public function getName(): string {
		return 'createwiki-populate-central-wiki';
	}

	public function getDescription(): string {
		return '[CreateWiki] Populating central wiki';
	}

	public function getDependencies(): array {
		return [ 'extension-tables', 'services' ];
	}

	public function execute(): Status {
		$connectionProvider = $this->getServices()->getConnectionProvider();
		$dbw = $connectionProvider->getPrimaryDatabase( 'virtual-createwiki' );
		$dbr = $connectionProvider->getReplicaDatabase( 'virtual-createwiki-central' );
		$centralWiki = $dbr->getDomainID();

		$exists = $dbw->newSelectQueryBuilder()
			->select( 'wiki_dbname' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_dbname' => $centralWiki ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $exists ) {
			// The central wiki is already populated.
			return Status::newGood();
		}

		$dbw->newInsertQueryBuilder()
			->insertInto( 'cw_wikis' )
			->row( [
				'wiki_dbname' => $centralWiki,
				'wiki_dbcluster' => null,
				'wiki_sitename' => $this->getConfigVar( MainConfigNames::Sitename ),
				'wiki_language' => $this->getConfigVar( MainConfigNames::LanguageCode ),
				'wiki_private' => 0,
				'wiki_creation' => $dbw->timestamp(),
				'wiki_category' => 'uncategorised',
			] )
			->caller( __METHOD__ )
			->execute();

		// Make sure tests know we populated this
		ChangedTablesTracker::recordQuery(
			$dbw,
			new Query(
				'INSERT', SQLPlatform::QUERY_CHANGE_ROWS,
				'INSERT', 'cw_wikis'
			)
		);

		return Status::newGood();
	}
}
