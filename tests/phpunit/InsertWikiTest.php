<?php

/**
 * @group CreateWiki
 * @group Database
 * @group Medium
 */
class InsertWikiTest extends MediaWikiIntegrationTestCase {
	protected $tablesUsed = [ 'cw_wikis' ];

	public function setUp(): void {
		parent::setUp();
		$this->addDBData();
	}

	public function addDBData() {
		parent::addDBData();

		$lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$lb = $lbFactory->newMainLB();
		$db = $lb->getConnection( DB_PRIMARY );

		MediaWikiIntegrationTestCase::setupDatabaseWithTestPrefix( $db, '' );

		$this->db->insert(
			'cw_wikis',
			[
				'wiki_dbname' => 'wikidb',
				'wiki_dbcluster' => 'c1',
				'wiki_sitename' => 'TestWiki',
				'wiki_language' => 'en',
				'wiki_private' => (int)0,
				'wiki_creation' => $this->db->timestamp(),
				'wiki_category' => 'uncategorised',
				'wiki_closed' => (int)0,
				'wiki_deleted' => (int)0,
				'wiki_locked' => (int)0,
				'wiki_inactive' => (int)0,
				'wiki_inactive_exempt' => (int)0,
				'wiki_url' => 'http://127.0.0.1:9412'
			],
			__METHOD__
			// [ 'IGNORE' ]
		);
	}
}
