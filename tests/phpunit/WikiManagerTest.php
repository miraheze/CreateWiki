<?php

/**
 * @group CreateWiki
 * @group Database
 * @group Medium
 * @coversDefaultClass WikiManager
 */
class WikiManagerTest extends MediaWikiIntegrationTestCase {
	public function setUp(): void {
		parent::setUp();

		$this->tablesUsed[] = 'cw_wikis';
	}

	/**
	 * @covers ::create
	 */
	public function testCreate() {
		$user = $this->getTestSysop()->getUser();

		$wikiManager = new WikiManager( 'createwikitest' );
		$wikiManager->create( 'TestWiki', 'en', 0, 'uncategorised', $user->getName(), $user, 'Test' );
	}
}
