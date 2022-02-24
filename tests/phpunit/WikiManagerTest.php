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
		$p = [
			'host' => '127.0.0.1',
			'serverName' => glob('/tmp/quibble-mysql-*/socket')[0],
			'user' => 'root',
			'dbname' => 'wikidb',
		];

		$db = Database::factory( 'mysql', $p );

		$db->query( "GRANT ALL PRIVILEGES ON *.* TO 'wikiuser'@'localhost'" );

		$user = $this->getTestSysop()->getUser();

		$wikiManager = new WikiManager( 'createwikitest' );

		$this->assertNull( $wikiManager->create( 'TestWiki', 'en', 0, 'uncategorised', $user->getName(), $user, 'Test' ) );
	}
}
