<?php

namespace Miraheze\CreateWiki\Tests;

use FatalError;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\RemoteWiki;
use Miraheze\CreateWiki\WikiManager;
use SiteConfiguration;

/**
 * @group CreateWiki
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\CreateWiki\WikiManager
 */
class WikiManagerTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();

		$conf = new SiteConfiguration();
		$conf->suffixes = [ 'test' ];

		$this->setMwGlobals( 'wgConf', $conf );

		$this->setMwGlobals( 'wgCreateWikiSQLfiles', [
			MW_INSTALL_PATH . '/maintenance/tables-generated.sql',
		] );

		$db = MediaWikiServices::getInstance()->getDatabaseFactory()->create( 'mysql', [
			'host' => $GLOBALS['wgDBserver'],
			'user' => 'root',
		] );

		$db->begin();
		$db->query( "GRANT ALL PRIVILEGES ON `createwikitest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `createwikiprivatetest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `deletewikitest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `recreatewikitest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `renamewikitest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "FLUSH PRIVILEGES;" );
		$db->commit();
	}

	public function addDBDataOnce(): void {
		try {
			$dbw = MediaWikiServices::getInstance()
				->getDBLoadBalancer()
				->getMaintenanceConnectionRef( DB_PRIMARY );

			$dbw->newInsertQueryBuilder()
				->insertInto( 'cw_wikis' )
				->ignore()
				->rows( [
					'wiki_dbname' => 'wikidb',
					'wiki_dbcluster' => 'c1',
					'wiki_sitename' => 'TestWiki',
					'wiki_language' => 'en',
					'wiki_private' => (int)0,
					'wiki_creation' => $dbw->timestamp(),
					'wiki_category' => 'uncategorised',
					'wiki_closed' => (int)0,
					'wiki_deleted' => (int)0,
					'wiki_locked' => (int)0,
					'wiki_inactive' => (int)0,
					'wiki_inactive_exempt' => (int)0,
					'wiki_url' => 'http://127.0.0.1:9412',
				] )
				->caller( __METHOD__ )
				->execute();

		} catch ( DBQueryError $e ) {
			// Do nothing
		}
	}

	/**
	 * @return CreateWikiHookRunner
	 */
	public function getMockCreateWikiHookRunner() {
		return $this->createMock( CreateWikiHookRunner::class );
	}

	/**
	 * @covers ::create
	 */
	public function testCreateSuccess() {
		$this->assertNull( $this->createWiki( 'createwikitest' ) );
		$this->assertTrue( $this->wikiExists( 'createwikitest' ) );
	}

	/**
	 * @covers ::create
	 */
	public function testCreatePrivate() {
		$this->assertNull( $this->createWiki( 'createwikiprivatetest', true ) );
		$this->assertTrue( $this->wikiExists( 'createwikiprivatetest' ) );
	}

	/**
	 * @covers ::create
	 */
	public function testCreateExists() {
		$this->expectException( FatalError::class );
		$this->expectExceptionMessage( 'Wiki \'createwikitest\' already exists.' );

		$this->createWiki( 'createwikitest' );
	}

	/**
	 * @covers ::checkDatabaseName
	 * @covers ::create
	 */
	public function testCreateErrors() {
		$notsuffixed = wfMessage( 'createwiki-error-notsuffixed' )->parse();
		$notalnum = wfMessage( 'createwiki-error-notalnum' )->parse();
		$notlowercase = wfMessage( 'createwiki-error-notlowercase' )->parse();

		$this->assertSame( $notsuffixed, $this->createWiki( 'createwiki' ) );
		$this->assertSame( $notalnum, $this->createWiki( 'create.wikitest' ) );
		$this->assertSame( $notlowercase, $this->createWiki( 'Createwikitest' ) );
	}

	/**
	 * @covers ::checkDatabaseName
	 * @covers ::rename
	 */
	public function testRenameErrors() {
		$wikiManager = new WikiManager( 'createwikitest', $this->getMockCreateWikiHookRunner() );

		$error = 'Can not rename createwikitest to renamewiki because: ';
		$notsuffixed = $error . wfMessage( 'createwiki-error-notsuffixed' )->parse();

		$error = 'Can not rename createwikitest to rename.wikitest because: ';
		$notalnum = $error . wfMessage( 'createwiki-error-notalnum' )->parse();

		$error = 'Can not rename createwikitest to Renamewikitest because: ';
		$notlowercase = $error . wfMessage( 'createwiki-error-notlowercase' )->parse();

		$this->assertSame( $notsuffixed, $wikiManager->rename( 'renamewiki' ) );
		$this->assertSame( $notalnum, $wikiManager->rename( 'rename.wikitest' ) );
		$this->assertSame( $notlowercase, $wikiManager->rename( 'Renamewikitest' ) );
	}

	/**
	 * @covers ::rename
	 */
	public function testRenameSuccess() {
		$this->createWiki( 'renamewikitest' );

		$this->db->delete( 'cw_wikis', [ 'wiki_dbname' => 'renamewikitest' ] );

		$wikiManager = new WikiManager( 'createwikitest', $this->getMockCreateWikiHookRunner() );

		$this->assertNull( $wikiManager->rename( 'renamewikitest' ) );
		$this->assertFalse( $this->wikiExists( 'createwikitest' ) );
		$this->assertTrue( $this->wikiExists( 'renamewikitest' ) );

		$this->db->query( 'DROP DATABASE `createwikitest`;' );
	}

	/**
	 * @covers ::delete
	 */
	public function testDeleteForce() {
		$wikiManager = new WikiManager( 'renamewikitest', $this->getMockCreateWikiHookRunner() );

		$this->assertNull( $wikiManager->delete( true ) );
		$this->assertFalse( $this->wikiExists( 'renamewikitest' ) );

		$this->db->query( 'DROP DATABASE `renamewikitest`;' );
	}

	/**
	 * @covers ::delete
	 */
	public function testDeleteIneligible() {
		$this->createWiki( 'deletewikitest' );

		$remoteWiki = new RemoteWiki( 'deletewikitest', $this->getMockCreateWikiHookRunner() );
		$remoteWiki->delete();
		$remoteWiki->commit();

		$this->assertTrue( (bool)$remoteWiki->isDeleted() );

		$wikiManager = new WikiManager( 'deletewikitest', $this->getMockCreateWikiHookRunner() );

		$this->assertSame( 'Wiki deletewikitest can not be deleted yet.', $wikiManager->delete() );
		$this->assertTrue( $this->wikiExists( 'deletewikitest' ) );

		$remoteWiki->undelete();
		$remoteWiki->commit();
	}

	/**
	 * @covers ::delete
	 */
	public function testDeleteEligible() {
		$wikiManager = new WikiManager( 'deletewikitest', $this->getMockCreateWikiHookRunner() );
		$this->assertSame( 'Wiki deletewikitest can not be deleted yet.', $wikiManager->delete() );

		$remoteWiki = new RemoteWiki( 'deletewikitest', $this->getMockCreateWikiHookRunner() );
		$remoteWiki->delete();
		$remoteWiki->commit();

		$this->assertTrue( (bool)$remoteWiki->isDeleted() );

		$eligibleTimestamp = wfTimestamp( TS_MW, wfTimestamp( TS_UNIX, $remoteWiki->isDeleted() ) - ( 86400 * 8 ) );
		$this->db->update( 'cw_wikis', [ 'wiki_deleted_timestamp' => $eligibleTimestamp ], [ 'wiki_dbname' => 'deletewikitest' ] );

		$this->assertNull( $wikiManager->delete() );
		$this->assertFalse( $this->wikiExists( 'deletewikitest' ) );

		$this->db->query( 'DROP DATABASE `deletewikitest`;' );
	}

	/**
	 * @covers ::create
	 * @covers ::delete
	 */
	public function testDeleteRecreate() {
		$this->createWiki( 'recreatewikitest' );

		$wikiManager = new WikiManager( 'recreatewikitest', $this->getMockCreateWikiHookRunner() );

		$this->assertNull( $wikiManager->delete( true ) );
		$this->assertFalse( $this->wikiExists( 'recreatewikitest' ) );

		$this->db->query( 'DROP DATABASE `recreatewikitest`;' );

		$this->assertNull( $this->createWiki( 'recreatewikitest' ) );
		$this->assertTrue( $this->wikiExists( 'recreatewikitest' ) );

		$wikiManager->delete( true );

		$this->db->query( 'DROP DATABASE `recreatewikitest`;' );
	}

	/**
	 * @param string $dbname
	 * @param bool $private
	 * @return mixed
	 */
	private function createWiki( string $dbname, bool $private = false ) {
		$testUser = $this->getTestUser()->getUser();
		$testSysop = $this->getTestSysop()->getUser();

		$wikiManager = new WikiManager( $dbname, $this->getMockCreateWikiHookRunner() );

		$this->setMwGlobals( 'wgLocalDatabases', array_merge(
			[ $dbname ], $GLOBALS['wgLocalDatabases']
		) );

		return $wikiManager->create( 'TestWiki', 'en', $private, 'uncategorised', $testUser->getName(), $testSysop->getName(), 'Test' );
	}

	/**
	 * @param string $dbname
	 * @return bool
	 */
	private function wikiExists( string $dbname ): bool {
		$wikiManager = new WikiManager( $dbname, $this->getMockCreateWikiHookRunner() );

		return $wikiManager->exists;
	}
}
