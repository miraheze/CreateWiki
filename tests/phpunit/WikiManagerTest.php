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
		$db->query( "GRANT ALL PRIVILEGES ON `deletewikitest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `renamewikitest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "FLUSH PRIVILEGES;" );
		$db->commit();
	}

	/**
	 * @covers ::create
	 */
	public function testCreate() {
		$this->assertNull( $this->createWiki( 'createwikitest' ) );
		$this->assertTrue( self::wikiExists( 'createwikitest' ) );
	}

	/**
	 * @covers ::rename
	 */
	public function testRename() {
		$this->createWiki( 'renamewikitest' );

		$this->db->delete( 'cw_wikis', [ 'wiki_dbname' => 'renamewikitest' ] );

		$wikiManager = new WikiManager( 'createwikitest' );

		$this->assertNull( $wikiManager->rename( 'renamewikitest' ) );
		$this->assertFalse( self::wikiExists( 'createwikitest' ) );
		$this->assertTrue( self::wikiExists( 'renamewikitest' ) );

		$this->db->query( 'DROP DATABASE `createwikitest`;' );
	}

	/**
	 * @covers ::delete
	 */
	public function testDeleteForce() {
		$wikiManager = new WikiManager( 'renamewikitest' );

		$this->assertNull( $wikiManager->delete( true ) );
		$this->assertFalse( self::wikiExists( 'renamewikitest' ) );

		$this->db->query( 'DROP DATABASE `renamewikitest`;' );
	}

	/**
	 * @covers ::delete
	 */
	public function testDeleteIneligible() {
		$this->createWiki( 'deletewikitest' );

		$remoteWiki = new RemoteWiki( 'deletewikitest' );
		$remoteWiki->delete();

		$this->assertTrue( (bool)$remoteWiki->isDeleted() );

		$wikiManager = new WikiManager( 'deletewikitest' );

		$this->assertSame( 'Wiki deletewikitest can not be deleted yet.', $wikiManager->delete() );
		$this->assertTrue( self::wikiExists( 'deletewikitest' ) );
	}

	/**
	 * @covers ::delete
	 */
	public function testDeleteEligible() {
		$remoteWiki = new RemoteWiki( 'deletewikitest' );
		$remoteWiki->delete();

		$this->assertTrue( (bool)$remoteWiki->isDeleted() );

		$eligibleTimestamp = $remoteWiki->isDeleted() - ( 86400 * 8 );
		$this->db->update( 'cw_wikis', [ 'wiki_deleted_timestamp' => $eligibleTimestamp ], [ 'wiki_dbname' => 'deletewikitest' ] );
		self::recache( 'deletewikitest' );

		$wikiManager = new WikiManager( 'deletewikitest' );

		$this->assertNull( $wikiManager->delete() );
		$this->assertFalse( self::wikiExists( 'deletewikitest' ) );

		$this->db->query( 'DROP DATABASE `deletewikitest`;' );
	}

	/**
	 * @param string $dbname
	 * @return mixed
	 */
	private function createWiki( string $dbname ) {
		$user = $this->getTestSysop()->getUser();

		$wikiManager = new WikiManager( $dbname );

		return $wikiManager->create( 'TestWiki', 'en', 0, 'uncategorised', $user->getName(), $user->getName(), 'Test' );
	}

	/**
	 * @param string $dbname
	 */
	private static function recache( string $dbname ) {
		$cWJ = new CreateWikiJson( $dbname );

		$cWJ->resetDatabaseList();
		$cWJ->resetWiki();
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
