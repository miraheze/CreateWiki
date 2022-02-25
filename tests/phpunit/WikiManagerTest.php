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

		$conf = new SiteConfiguration();
		$conf->suffixes = [ 'test' ];
		$this->setMwGlobals( [
			'wgConf' => $conf,
		] );

		$db = Database::factory( 'mysql', [ 'host' => $GLOBALS['wgDBserver'], 'user' => 'root' ] );

		$db->begin();
		$db->query( "GRANT ALL PRIVILEGES ON `createwikitest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `renamewikitest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "FLUSH PRIVILEGES;" );
		$db->commit();
	}

	/**
	 * @covers ::create
	 */
	public function testCreate() {
		$userName = $this->getTestSysop()->getUser()->getName();

		$wikiManager = new WikiManager( 'createwikitest' );

		$this->assertNull( $wikiManager->create( 'TestWiki', 'en', 0, 'uncategorised', $userName, $userName, 'Test' ) );
		$this->assertTrue( self::wikiExists( 'createwikitest' ) );
	}

	/**
	 * @covers ::rename
	 */
	public function testRename() {
		$wikiManagerOld = new WikiManager( 'createwikitest' );
		$wikiManagerNew = new WikiManager( 'renamewikitest' );

		$userName = $this->getTestSysop()->getUser()->getName();
		$wikiManagerNew->create( 'TestWiki', 'en', 0, 'uncategorised', $userName, $userName, 'Test' );

		$this->db->delete( 'cw_wikis', [ 'wiki_dbname' => 'renamewikitest' ] );

		$this->assertNull( $wikiManagerOld->rename( 'renamewikitest' ) );

		$this->assertFalse( self::wikiExists( 'createwikitest' ) );
		$this->assertTrue( self::wikiExists( 'renamewikitest' ) );

		$this->db->query( 'DROP DATABASE `createwikitest`;' );
	}

	/**
	 * @covers ::delete
	 */
	public function testDelete() {
		$wikiManager = new WikiManager( 'renamewikitest' );

		$this->assertNull( $wikiManager->delete( true ) );
		$this->assertFalse( self::wikiExists( 'renamewikitest' ) );

		$this->db->query( 'DROP DATABASE `renamewikitest`;' );
	}

	/**
	 * @param string $dbname
	 * @return bool
	 */
	private static function wikiExists( string $dbname ): bool {
		$wikiManager = new WikiManager( $dbname );

		return $wikiManager->exists;
	}
}
