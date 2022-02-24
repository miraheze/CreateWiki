<?php

use Wikimedia\Rdbms\Database;

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

		$conf = new SiteConfiguration();
		$conf->suffixes = [ 'test' ];
		$this->setMwGlobals( [
			'wgConf' => $conf,
		] );
	}

	/**
	 * @covers ::create
	 */
	public function testCreate() {
		global $wgDBserver;

		$p = [
			'host' => $wgDBserver,
			'user' => 'root',
			'dbname' => false,
		];

		$db = Database::factory( 'mysql', $p );

		$db->begin( __METHOD__ );
		$db->query( "GRANT SELECT, INSERT, UPDATE, DELETE, DROP, CREATE, ALTER, INDEX, CREATE VIEW, LOCK TABLES ON `createwikitest`.* TO 'wikiuser'@'localhost' WITH GRANT OPTION;", __METHOD__ );
		$db->query( "FLUSH PRIVILEGES;", __METHOD__ );
		$db->commit( __METHOD__ );

		$user = $this->getTestSysop()->getUser();

		$wikiManager = new WikiManager( 'createwikitest' );

		$this->assertNull( $wikiManager->create( 'TestWiki', 'en', 0, 'uncategorised', $user->getName(), $user, 'Test' ) );
	}
}
