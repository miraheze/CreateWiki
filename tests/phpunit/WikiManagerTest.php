<?php

namespace Miraheze\CreateWiki\Tests;

use FatalError;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use Miraheze\CreateWiki\WikiManager;

/**
 * @group CreateWiki
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\CreateWiki\WikiManager
 */
class WikiManagerTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setMwGlobals( 'wgCreateWikiDatabaseSuffix', 'test' );
		$this->setMwGlobals( 'wgCreateWikiSQLfiles', [
			MW_INSTALL_PATH . '/maintenance/tables-generated.sql',
		] );

		$db = $this->getServiceContainer()->getDatabaseFactory()->create( 'mysql', [
			'host' => $GLOBALS['wgDBserver'],
			'user' => 'root',
		] );

		$db->begin();
		$db->query( "GRANT ALL PRIVILEGES ON `createwikilegacytest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `createwikiprivatelegacytest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `deletewikilegacytest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `recreatewikilegacytest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `renamewikilegacytest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "FLUSH PRIVILEGES;" );
		$db->commit();
	}

	public function addDBDataOnce(): void {
		try {
			$dbw = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase();

			$dbw->newInsertQueryBuilder()
				->insertInto( 'cw_wikis' )
				->ignore()
				->row( [
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
	 * @return RemoteWikiFactory
	 */
	public function getRemoteWikiFactory(): RemoteWikiFactory {
		return $this->getServiceContainer()->get( 'RemoteWikiFactory' );
	}

	/**
	 * @covers ::create
	 */
	public function testCreateSuccess() {
		$this->assertNull( $this->createWiki( 'createwikilegacytest' ) );
		$this->assertTrue( $this->wikiExists( 'createwikilegacytest' ) );
	}

	/**
	 * @covers ::create
	 */
	public function testCreatePrivate() {
		$this->assertNull( $this->createWiki( 'createwikiprivatelegacytest', true ) );
		$this->assertTrue( $this->wikiExists( 'createwikiprivatelegacytest' ) );
	}

	/**
	 * @covers ::create
	 */
	public function testCreateExists() {
		$this->expectException( FatalError::class );
		$this->expectExceptionMessage( 'Wiki \'createwikilegacytest\' already exists.' );

		$this->createWiki( 'createwikilegacytest' );
	}

	/**
	 * @covers ::create
	 */
	public function testCreateErrors() {
		$notsuffixed = wfMessage( 'createwiki-error-notsuffixed', 'test' )->parse();
		$notalnum = wfMessage( 'createwiki-error-notalnum' )->parse();
		$notlowercase = wfMessage( 'createwiki-error-notlowercase' )->parse();

		$this->assertSame( $notsuffixed, $this->createWiki( 'createwiki' ) );
		$this->assertSame( $notalnum, $this->createWiki( 'create.wikitest' ) );
		$this->assertSame( $notlowercase, $this->createWiki( 'Createwikitest' ) );
	}

	/**
	 * @covers ::rename
	 */
	public function testRenameErrors() {
		$wikiManager = new WikiManager( 'createwikilegacytest', $this->getMockCreateWikiHookRunner() );

		$error = 'Can not rename createwikilegacytest to renamewiki because: ';
		$notsuffixed = $error . wfMessage( 'createwiki-error-notsuffixed', 'test' )->parse();

		$error = 'Can not rename createwikilegacytest to rename.wikitest because: ';
		$notalnum = $error . wfMessage( 'createwiki-error-notalnum' )->parse();

		$error = 'Can not rename createwikilegacytest to Renamewikitest because: ';
		$notlowercase = $error . wfMessage( 'createwiki-error-notlowercase' )->parse();

		$this->assertSame( $notsuffixed, $wikiManager->rename( 'renamewiki' ) );
		$this->assertSame( $notalnum, $wikiManager->rename( 'rename.wikitest' ) );
		$this->assertSame( $notlowercase, $wikiManager->rename( 'Renamewikitest' ) );
	}

	/**
	 * @covers ::rename
	 */
	public function testRenameSuccess() {
		$this->createWiki( 'renamewikilegacytest' );

		$this->db->delete( 'cw_wikis', [ 'wiki_dbname' => 'renamewikilegacytest' ] );

		$wikiManager = new WikiManager( 'createwikilegacytest', $this->getMockCreateWikiHookRunner() );

		$this->assertNull( $wikiManager->rename( 'renamewikilegacytest' ) );
		$this->assertFalse( $this->wikiExists( 'createwikilegacytest' ) );
		$this->assertTrue( $this->wikiExists( 'renamewikilegacytest' ) );

		$this->db->query( 'DROP DATABASE `createwikilegacytest`;' );
	}

	/**
	 * @covers ::delete
	 */
	public function testDeleteForce() {
		$wikiManager = new WikiManager( 'renamewikilegacytest', $this->getMockCreateWikiHookRunner() );

		$this->assertNull( $wikiManager->delete( true ) );
		$this->assertFalse( $this->wikiExists( 'renamewikilegacytest' ) );

		$this->db->query( 'DROP DATABASE `renamewikilegacytest`;' );
	}

	/**
	 * @covers ::delete
	 */
	public function testDeleteIneligible() {
		$this->createWiki( 'deletewikilegacytest' );

		$remoteWiki = $this->getRemoteWikiFactory()->newInstance( 'deletewikilegacytest' );

		$remoteWiki->delete();
		$remoteWiki->commit();

		$this->assertTrue( (bool)$remoteWiki->isDeleted() );

		$wikiManager = new WikiManager( 'deletewikilegacytest', $this->getMockCreateWikiHookRunner() );

		$this->assertSame( 'Wiki deletewikilegacytest can not be deleted yet.', $wikiManager->delete() );
		$this->assertTrue( $this->wikiExists( 'deletewikilegacytest' ) );

		$remoteWiki->undelete();
		$remoteWiki->commit();
	}

	/**
	 * @covers ::delete
	 */
	public function testDeleteEligible() {
		$wikiManager = new WikiManager( 'deletewikilegacytest', $this->getMockCreateWikiHookRunner() );
		$this->assertSame( 'Wiki deletewikilegacytest can not be deleted yet.', $wikiManager->delete() );

		$remoteWiki = $this->getRemoteWikiFactory()->newInstance( 'deletewikilegacytest' );

		$remoteWiki->delete();
		$remoteWiki->commit();

		$this->assertTrue( (bool)$remoteWiki->isDeleted() );

		$eligibleTimestamp = wfTimestamp( TS_MW, wfTimestamp( TS_UNIX, $remoteWiki->isDeleted() ) - ( 86400 * 8 ) );
		$this->db->update(
			'cw_wikis',
			[ 'wiki_deleted_timestamp' => $eligibleTimestamp ],
			[ 'wiki_dbname' => 'deletewikilegacytest' ]
		);

		$this->assertNull( $wikiManager->delete() );
		$this->assertFalse( $this->wikiExists( 'deletewikilegacytest' ) );

		$this->db->query( 'DROP DATABASE `deletewikilegacytest`;' );
	}

	/**
	 * @covers ::create
	 * @covers ::delete
	 */
	public function testDeleteRecreate() {
		$this->createWiki( 'recreatewikilegacytest' );

		$wikiManager = new WikiManager( 'recreatewikilegacytest', $this->getMockCreateWikiHookRunner() );

		$this->assertNull( $wikiManager->delete( true ) );
		$this->assertFalse( $this->wikiExists( 'recreatewikilegacytest' ) );

		$this->db->query( 'DROP DATABASE `recreatewikilegacytest`;' );

		$this->assertNull( $this->createWiki( 'recreatewikilegacytest' ) );
		$this->assertTrue( $this->wikiExists( 'recreatewikilegacytest' ) );

		$wikiManager->delete( true );

		$this->db->query( 'DROP DATABASE `recreatewikilegacytest`;' );
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

		return $wikiManager->create(
			'TestWiki', 'en', $private, 'uncategorised', $testUser->getName(), $testSysop->getName(), 'Test'
		);
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
