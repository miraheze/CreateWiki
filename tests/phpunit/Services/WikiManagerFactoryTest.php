<?php

namespace Miraheze\CreateWiki\Tests\Services;

use FatalError;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Wikimedia\Rdbms\LBFactoryMulti;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Services\WikiManagerFactory
 */
class WikiManagerFactoryTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			ConfigNames::DatabaseClusters => [ 'c1', 'c2' ],
			ConfigNames::DatabaseSuffix => 'test',
			ConfigNames::SQLFiles => [
				MW_INSTALL_PATH . '/maintenance/tables-generated.sql',
			],
		] );

		$db = $this->getServiceContainer()->getDatabaseFactory()->create( 'mysql', [
			'host' => $this->getConfVar( MainConfigNames::DBserver ),
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
		$dbw = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'cw_wikis' )
			->ignore()
			->row( [
				'wiki_dbname' => 'wikidb',
				'wiki_dbcluster' => 'c1',
				'wiki_sitename' => 'TestWiki',
				'wiki_language' => 'en',
				'wiki_private' => 0,
				'wiki_creation' => $dbw->timestamp(),
				'wiki_category' => 'uncategorised',
				'wiki_closed' => 0,
				'wiki_deleted' => 0,
				'wiki_locked' => 0,
				'wiki_inactive' => 0,
				'wiki_inactive_exempt' => 0,
				'wiki_url' => 'http://127.0.0.1:9412',
			] )
			->caller( __METHOD__ )
			->execute();
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
	public function testCreateSuccess(): void {
		$this->assertNull( $this->createWiki( dbname: 'createwikitest', private: false ) );
		$this->assertTrue( $this->wikiExists( 'createwikitest' ) );
	}

	/**
	 * @covers ::create
	 */
	public function testCreatePrivate(): void {
		$this->assertNull( $this->createWiki( dbname: 'createwikiprivatetest', private: true ) );
		$this->assertTrue( $this->wikiExists( 'createwikiprivatetest' ) );
	}

	/**
	 * @covers ::create
	 */
	public function testCreateExists(): void {
		$this->expectException( FatalError::class );
		$this->expectExceptionMessage( 'Wiki \'createwikitest\' already exists.' );

		$this->createWiki( dbname: 'createwikitest', private: false );
	}

	/**
	 * @covers ::checkDatabaseName
	 * @covers ::create
	 */
	public function testCreateErrors(): void {
		$notsuffixed = wfMessage( 'createwiki-error-notsuffixed', 'test' )->parse();
		$notalnum = wfMessage( 'createwiki-error-notalnum' )->parse();
		$notlowercase = wfMessage( 'createwiki-error-notlowercase' )->parse();

		$this->assertSame( $notsuffixed, $this->createWiki( dbname: 'createwiki', private: false ) );
		$this->assertSame( $notalnum, $this->createWiki( dbname: 'create.wikitest', private: false ) );
		$this->assertSame( $notlowercase, $this->createWiki( dbname: 'Createwikitest', private: false ) );
	}

	/**
	 * @covers ::checkDatabaseName
	 * @covers ::rename
	 */
	public function testRenameErrors(): void {
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
	public function testRenameSuccess(): void {
		$this->createWiki( dbname: 'renamewikitest', private: false );

		$this->db->newDeleteQueryBuilder()
			->deleteFrom( 'cw_wikis' )
			->where( [ 'wiki_dbname' => 'renamewikitest' ] )
			->caller( __METHOD__ )
			->execute();

		$wikiManager = $this->getFactoryService()->newInstance( 'createwikitest' );

		$this->assertNull( $wikiManager->rename( 'renamewikitest' ) );
		$this->assertFalse( $this->wikiExists( 'createwikitest' ) );
		$this->assertTrue( $this->wikiExists( 'renamewikitest' ) );

		$this->db->query( 'DROP DATABASE `createwikitest`;' );
	}

	/**
	 * @covers ::delete
	 */
	public function testDeleteForce(): void {
		$this->setupLBFactory();
		$wikiManager = $this->getFactoryService()->newInstance( 'renamewikitest' );

		$this->assertNull( $wikiManager->delete( force: true ) );
		$this->assertFalse( $this->wikiExists( 'renamewikitest' ) );

		$this->db->query( 'DROP DATABASE `renamewikitest`;' );
	}

	/**
	 * @covers ::delete
	 */
	public function testDeleteIneligible(): void {
		$this->createWiki( dbname: 'deletewikitest', private: false );

		$remoteWiki = $this->getRemoteWikiFactory()->newInstance( 'deletewikitest' );

		$remoteWiki->delete();
		$remoteWiki->commit();

		$this->assertTrue( $remoteWiki->isDeleted() );

		$wikiManager = $this->getFactoryService()->newInstance( 'deletewikitest' );

		$this->assertSame( 'Wiki deletewikitest can not be deleted yet.', $wikiManager->delete( force: false ) );
		$this->assertTrue( $this->wikiExists( 'deletewikitest' ) );

		$remoteWiki->undelete();
		$remoteWiki->commit();
	}

	/**
	 * @covers ::delete
	 */
	public function testDeleteEligible(): void {
		$this->setupLBFactory();
		$wikiManager = $this->getFactoryService()->newInstance( 'deletewikitest' );
		$this->assertSame( 'Wiki deletewikitest can not be deleted yet.', $wikiManager->delete( force: false ) );

		$remoteWiki = $this->getRemoteWikiFactory()->newInstance( 'deletewikitest' );

		$remoteWiki->delete();
		$remoteWiki->commit();

		$this->assertTrue( $remoteWiki->isDeleted() );

		$eligibleTimestamp = wfTimestamp( TS_MW, wfTimestamp(
			TS_UNIX,
			$remoteWiki->getDeletedTimestamp()
		) - ( 86400 * 8 ) );

		$this->db->newUpdateQueryBuilder()
			->update( 'cw_wikis' )
			->set( [ 'wiki_deleted_timestamp' => $eligibleTimestamp ] )
			->where( [ 'wiki_dbname' => 'deletewikitest' ] )
			->caller( __METHOD__ )
			->execute();

		$this->assertNull( $wikiManager->delete( force: false ) );
		$this->assertFalse( $this->wikiExists( 'deletewikitest' ) );

		$this->db->query( 'DROP DATABASE `deletewikitest`;' );
	}

	/**
	 * @covers ::create
	 * @covers ::delete
	 */
	public function testDeleteRecreate(): void {
		$this->createWiki( dbname: 'recreatewikitest', private: false );

		$wikiManager = $this->getFactoryService()->newInstance( 'recreatewikitest' );

		$this->assertNull( $wikiManager->delete( force: true ) );
		$this->assertFalse( $this->wikiExists( 'recreatewikitest' ) );

		$this->db->query( 'DROP DATABASE `recreatewikitest`;' );

		$this->assertNull( $this->createWiki( dbname: 'recreatewikitest', private: false ) );
		$this->assertTrue( $this->wikiExists( 'recreatewikitest' ) );

		$wikiManager->delete( force: true );

		$this->db->query( 'DROP DATABASE `recreatewikitest`;' );
	}

	/**
	 * @param string $dbname
	 * @param bool $private
	 * @return ?string
	 */
	private function createWiki( string $dbname, bool $private ): ?string {
		$testUser = $this->getTestUser()->getUser();
		$testSysop = $this->getTestSysop()->getUser();

		$this->setupLBFactory();
		$wikiManager = $this->getFactoryService()->newInstance( $dbname );

		$this->overrideConfigValue( MainConfigNames::LocalDatabases, array_merge(
			[ $dbname ], $this->getConfVar( MainConfigNames::LocalDatabases )
		) );

		return $wikiManager->create(
			'TestWiki', 'en', $private, 'uncategorised',
			$testUser->getName(), $testSysop->getName(),
			'Test', []
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

	private function setupLBFactory(): void {
		wfLoadConfiguration();
		$this->overrideConfigValue( MainConfigNames::LBFactoryConf, [
			'class' => LBFactoryMulti::class,
			'secret' => $this->getConfVar( MainConfigNames::SecretKey ),
			'sectionsByDB' => $this->getConfVar( 'WikiInitialize' )->wikiDBClusters,
			'sectionLoads' => [
				'DEFAULT' => [
					'c1' => 0,
				],
				'c1' => [
					'c1' => 0,
				],
				'c2' => [
					'c2' => 0,
				],
			],
			'serverTemplate' => [
				'dbname' => $this->getConfVar( MainConfigNames::DBname ),
				'user' => 'root',
				'type' => 'mysql',
				'flags' => DBO_DEFAULT | DBO_DEBUG,
			],
			'hostsByName' => [
				'c1' => $this->getConfVar( MainConfigNames::DBserver ),
				'c2' => $this->getConfVar( MainConfigNames::DBserver ),
			],
		] );
	}
}
