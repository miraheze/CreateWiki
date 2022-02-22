<?php

/**
 * @group Database
 * @group Medium
 */
class InsertWikiTest extends MediaWikiIntegrationTestCase {
	protected $tablesUsed = [ 'cw_wikis' ];

	public const DB_PREFIX = '';

	public function setUp(): void {
		parent::setUp();
	}

	public function addDBData() {
		parent::addDBData();
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
		);
	}
}
