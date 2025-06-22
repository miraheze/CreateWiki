<?php

namespace Miraheze\CreateWiki\Installer;

use MediaWiki\Installer\Task\Task;
use MediaWiki\MainConfigNames;
use MediaWiki\Status\Status;

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

		return Status::newGood();
	}
}
