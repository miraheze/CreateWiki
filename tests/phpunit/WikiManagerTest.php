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
		global $wgDBserver;

		parent::setUp();

		$this->tablesUsed[] = 'cw_wikis';

		$conf = new SiteConfiguration();
		$conf->suffixes = [ 'test' ];
		$this->setMwGlobals( [
			'wgConf' => $conf,
		] );

		$db = Database::factory( 'mysql', [ 'host' => $wgDBserver, 'user' => 'root' ] );

		$db->begin( __METHOD__ );
		$db->query( "GRANT ALL PRIVILEGES ON *.* TO 'wikiuser'@'localhost';", __METHOD__ );
		$db->query( "FLUSH PRIVILEGES;", __METHOD__ );
		$db->commit( __METHOD__ );
	}

	/**
	 * @covers ::create
	 */
	public function testCreate() {
		$user = $this->getTestSysop()->getUser();

		$wikiManager = new WikiManager( 'createwikitest' );

		$this->assertNull( $wikiManager->create( 'TestWiki', 'en', 0, 'uncategorised', $user->getName(), $user, 'Test' ) );
	}

	/**
	 * @covers ::rename
	 */
	public function testRename() {
		$wikiManager = new WikiManager( 'createwikitest' );

		$this->assertNull( $wikiManager->rename( 'renamewikitest' ) );
	}

	/**
	 * @covers ::delete
	 */
	public function testDelete() {
		$wikiManager = new WikiManager( 'renamewikitest' );

		$this->assertNull( $wikiManager->delete( true ) );
	}
}
