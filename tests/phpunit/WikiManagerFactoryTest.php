<?php

namespace Miraheze\CreateWiki\Tests;

use FatalError;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use Miraheze\CreateWiki\Services\WikiManagerFactory;

/**
 * @group CreateWiki
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\CreateWiki\Services\WikiManagerFactory
 */
class WikiManagerFactoryTest extends MediaWikiIntegrationTestCase {

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
			$dbw = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase();

			$dbw->insert(
				'cw_wikis',
				[
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
					'wiki_url' => 'http://127.0.0.1:9412'
				],
				__METHOD__,
				[ 'IGNORE' ]
			);

		} catch ( DBQueryError $e ) {
			// Do nothing
		}
	}

	/**
	 * @return WikiManagerFactory
	 */
	public function getFactoryService(): WikiManagerFactory {
		return $this->getServiceContainer()->get( 'WikiManagerFactory' );
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
		$notsuffixed = wfMessage( 'createwiki-error-notsuffixed', 'test' )->parse();
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
		$wikiManager = $this->getFactoryService()->newInstance( 'createwikitest' );

		$error = 'Can not rename createwikitest to renamewiki because: ';
		$notsuffixed = $error . wfMessage( 'createwiki-error-notsuffixed', 'test' )->parse();

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

		$wikiManager = $this->getFactoryService()->newInstance( 'createwikitest' );

		$this->assertNull( $wikiManager->rename( 'renamewikitest' ) );
		$this->assertFalse( $this->wikiExists( 'createwikitest' ) );
		$this->assertTrue( $this->wikiExists( 'renamewikitest' ) );

		$this->db->query( 'DROP DATABASE `createwikitest`;' );
	}

	/**
	 * @covers ::delete
	 */
	public function testDeleteForce() {
		$wikiManager = $this->getFactoryService()->newInstance( 'renamewikitest' );

		$this->assertNull( $wikiManager->delete( force: true ) );
		$this->assertFalse( $this->wikiExists( 'renamewikitest' ) );

		$this->db->query( 'DROP DATABASE `renamewikitest`;' );
	}

	/**
	 * @covers ::delete
	 */
	public function testDeleteIneligible() {
		$this->createWiki( 'deletewikitest' );

		$remoteWiki = $this->getRemoteWikiFactory()->newInstance( 'deletewikitest' );

		$remoteWiki->delete();
		$remoteWiki->commit();

		$this->assertTrue( (bool)$remoteWiki->isDeleted() );

		$wikiManager = $this->getFactoryService()->newInstance( 'deletewikitest' );

		$this->assertSame( 'Wiki deletewikitest can not be deleted yet.', $wikiManager->delete( force: false ) );
		$this->assertTrue( $this->wikiExists( 'deletewikitest' ) );

		$remoteWiki->undelete();
		$remoteWiki->commit();
	}

	/**
	 * @covers ::delete
	 */
	public function testDeleteEligible() {
		$wikiManager = $this->getFactoryService()->newInstance( 'deletewikitest' );
		$this->assertSame( 'Wiki deletewikitest can not be deleted yet.', $wikiManager->delete( force: false ) );

		$remoteWiki = $this->getRemoteWikiFactory()->newInstance( 'deletewikitest' );

		$remoteWiki->delete();
		$remoteWiki->commit();

		$this->assertTrue( (bool)$remoteWiki->isDeleted() );

		$eligibleTimestamp = wfTimestamp( TS_MW, wfTimestamp( TS_UNIX, $remoteWiki->isDeleted() ) - ( 86400 * 8 ) );
		$this->db->update(
			'cw_wikis',
			[ 'wiki_deleted_timestamp' => $eligibleTimestamp ],
			[ 'wiki_dbname' => 'deletewikitest' ]
		);

		$this->assertNull( $wikiManager->delete( force: false ) );
		$this->assertFalse( $this->wikiExists( 'deletewikitest' ) );

		$this->db->query( 'DROP DATABASE `deletewikitest`;' );
	}

	/**
	 * @covers ::create
	 * @covers ::delete
	 */
	public function testDeleteRecreate() {
		$this->createWiki( 'recreatewikitest' );

		$wikiManager = $this->getFactoryService()->newInstance( 'recreatewikitest' );

		$this->assertNull( $wikiManager->delete( force: true ) );
		$this->assertFalse( $this->wikiExists( 'recreatewikitest' ) );

		$this->db->query( 'DROP DATABASE `recreatewikitest`;' );

		$this->assertNull( $this->createWiki( 'recreatewikitest' ) );
		$this->assertTrue( $this->wikiExists( 'recreatewikitest' ) );

		$wikiManager->delete( force: true );

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

		$wikiManager = $this->getFactoryService()->newInstance( $dbname );

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
		$wikiManager = $this->getFactoryService()->newInstance( $dbname );

		return $wikiManager->exists();
	}
}