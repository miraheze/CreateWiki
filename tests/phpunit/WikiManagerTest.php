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
		$db->query( "GRANT ALL PRIVILEGES ON `recreatewikitest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `renamewikitest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "FLUSH PRIVILEGES;" );
		$db->commit();
	}

	/**
	 * @covers ::create
	 */
	public function testCreateSuccess() {
		$this->assertNull( $this->createWiki( 'createwikitest' ) );
		$this->assertTrue( self::wikiExists( 'createwikitest' ) );
	}

	/**
	 * @covers ::create
	 */
	public function testCreateExists() {
		$this->createWiki( 'createwikitest' );

		$this->expectException( FatalError::class );
		$this->expectExceptionMessage( 'Wiki \'createwikitest\' already exists.' );

		$this->assertFalse( self::wikiExists( 'createwikitest' ) );
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

		$eligibleTimestamp = wfTimestamp( TS_MW, wfTimestamp( TS_UNIX, $remoteWiki->isDeleted() ) - ( 86400 * 8 ) );
		$this->db->update( 'cw_wikis', [ 'wiki_deleted_timestamp' => $eligibleTimestamp ], [ 'wiki_dbname' => 'deletewikitest' ] );

		$wikiManager = new WikiManager( 'deletewikitest' );

		$this->assertNull( $wikiManager->delete() );
		$this->assertFalse( self::wikiExists( 'deletewikitest' ) );

		$this->db->query( 'DROP DATABASE `deletewikitest`;' );
	}

	/**
	 * @covers ::create
	 * @covers ::delete
	 */
	public function testDeleteRecreate() {
		$this->createWiki( 'recreatewikitest' );

		$wikiManager = new WikiManager( 'recreatewikitest' );

		$this->assertNull( $wikiManager->delete( true ) );
		$this->assertFalse( self::wikiExists( 'recreatewikitest' ) );

		$this->db->query( 'DROP DATABASE `recreatewikitest`;' );

		$this->assertNull( $this->createWiki( 'recreatewikitest' ) );
		$this->assertTrue( self::wikiExists( 'recreatewikitest' ) );

		$wikiManager->delete( true );

		$this->db->query( 'DROP DATABASE `recreatewikitest`;' );
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
	 * @return bool
	 */
	private static function wikiExists( string $dbname ): bool {
		$wikiManager = new WikiManager( $dbname );

		return $wikiManager->exists;
	}
}
