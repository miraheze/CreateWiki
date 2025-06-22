<?php

namespace Miraheze\CreateWiki\Tests\Services;

use FatalError;
use MediaWiki\Config\ConfigException;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Wikimedia\Rdbms\LBFactoryMulti;
use function array_merge;
use function version_compare;
use function wfLoadConfiguration;
use function wfMessage;
use function wfTimestamp;
use const DBO_DEBUG;
use const DBO_DEFAULT;
use const MW_INSTALL_PATH;
use const MW_VERSION;
use const TS_MW;
use const TS_UNIX;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Services\WikiManagerFactory
 */
class WikiManagerFactoryTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$sqlPath = '/maintenance/tables-generated.sql';
		if ( version_compare( MW_VERSION, '1.44', '>=' ) ) {
			$sqlPath = '/sql/mysql/tables-generated.sql';
		}

		$this->overrideConfigValues( [
			ConfigNames::DatabaseClusters => [ 'c1', 'c2' ],
			ConfigNames::DatabaseSuffix => 'test',
			ConfigNames::SQLFiles => [
				MW_INSTALL_PATH . $sqlPath,
			],
		] );

		$db = $this->getServiceContainer()->getDatabaseFactory()->create( 'mysql', [
			'host' => $this->getConfVar( MainConfigNames::DBserver ),
			'user' => 'root',
		] );

		if ( $db === null ) {
			return;
		}

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
		$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
		'@phan-var CreateWikiDatabaseUtils $databaseUtils';
		$dbw = $databaseUtils->getGlobalPrimaryDB();
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

	public function getFactoryService(): WikiManagerFactory {
		return $this->getServiceContainer()->get( 'WikiManagerFactory' );
	}

	public function getRemoteWikiFactory(): RemoteWikiFactory {
		return $this->getServiceContainer()->get( 'RemoteWikiFactory' );
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$this->assertInstanceOf( WikiManagerFactory::class, $this->getFactoryService() );
	}

	/**
	 * @covers ::newInstance
	 */
	public function testNewInstanceException(): void {
		$this->expectException( ConfigException::class );
		$this->expectExceptionMessage( 'Must use LBFactoryMulti when using clusters with CreateWiki.' );
		$this->getFactoryService()->newInstance( 'newwiki' );
	}

	/**
	 * @covers ::newInstance
	 */
	public function testNewInstance(): void {
		$this->setupLBFactory();
		$factory = $this->getFactoryService()->newInstance( 'newwiki' );

		$this->assertInstanceOf( WikiManagerFactory::class, $factory );
		$this->assertFalse( $factory->exists() );

		$factory = $this->getFactoryService()->newInstance( 'wikidb' );
		$this->assertInstanceOf( WikiManagerFactory::class, $factory );
		$this->assertTrue( $factory->exists() );
	}

	/**
	 * @covers ::create
	 * @covers ::doAfterCreate
	 * @covers ::doCreateDatabase
	 * @covers ::exists
	 * @covers ::logEntry
	 */
	public function testCreateSuccess(): void {
		$this->assertNull( $this->createWiki( dbname: 'createwikitest', private: false ) );
		$this->assertTrue( $this->wikiExists( 'createwikitest' ) );
	}

	/**
	 * @covers ::create
	 * @covers ::doAfterCreate
	 * @covers ::doCreateDatabase
	 * @covers ::exists
	 */
	public function testCreatePrivate(): void {
		$this->assertNull( $this->createWiki( dbname: 'createwikiprivatetest', private: true ) );
		$this->assertTrue( $this->wikiExists( 'createwikiprivatetest' ) );
	}

	/**
	 * @covers ::create
	 * @covers ::doAfterCreate
	 * @covers ::doCreateDatabase
	 * @covers ::exists
	 */
	public function testCreateExists(): void {
		$this->expectException( FatalError::class );
		$this->expectExceptionMessage( 'Wiki \'createwikitest\' already exists.' );

		$this->createWiki( dbname: 'createwikitest', private: false );
	}

	/**
	 * @covers ::create
	 * @covers ::doAfterCreate
	 * @covers ::doCreateDatabase
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
	 * @covers ::compileTables
	 * @covers ::recache
	 * @covers ::rename
	 */
	public function testRenameSuccess(): void {
		$this->createWiki( dbname: 'renamewikitest', private: false );

		$this->getDb()->newDeleteQueryBuilder()
			->deleteFrom( 'cw_wikis' )
			->where( [ 'wiki_dbname' => 'renamewikitest' ] )
			->caller( __METHOD__ )
			->execute();

		$wikiManager = $this->getFactoryService()->newInstance( 'createwikitest' );

		$this->assertNull( $wikiManager->rename( 'renamewikitest' ) );
		$this->assertFalse( $this->wikiExists( 'createwikitest' ) );
		$this->assertTrue( $this->wikiExists( 'renamewikitest' ) );

		$this->getDb()->query( 'DROP DATABASE `createwikitest`;', __METHOD__ );
	}

	/**
	 * @covers ::delete
	 */
	public function testDeleteForce(): void {
		$this->setupLBFactory();
		$wikiManager = $this->getFactoryService()->newInstance( 'renamewikitest' );

		$this->assertNull( $wikiManager->delete( force: true ) );
		$this->assertFalse( $this->wikiExists( 'renamewikitest' ) );

		$this->getDb()->query( 'DROP DATABASE `renamewikitest`;', __METHOD__ );
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
	 * @covers ::compileTables
	 * @covers ::delete
	 * @covers ::recache
	 */
	public function testDeleteEligible(): void {
		$this->setupLBFactory();
		$wikiManager = $this->getFactoryService()->newInstance( 'deletewikitest' );
		$this->assertSame( 'Wiki deletewikitest can not be deleted yet.', $wikiManager->delete( force: false ) );

		$remoteWiki = $this->getRemoteWikiFactory()->newInstance( 'deletewikitest' );

		$remoteWiki->delete();
		$remoteWiki->commit();

		$this->assertTrue( $remoteWiki->isDeleted() );

		$eligibleTimestamp = wfTimestamp( TS_MW, (int)wfTimestamp(
			TS_UNIX,
			$remoteWiki->getDeletedTimestamp()
		) - ( 86400 * 8 ) );

		$this->getDb()->newUpdateQueryBuilder()
			->update( 'cw_wikis' )
			->set( [ 'wiki_deleted_timestamp' => $eligibleTimestamp ] )
			->where( [ 'wiki_dbname' => 'deletewikitest' ] )
			->caller( __METHOD__ )
			->execute();

		$this->assertNull( $wikiManager->delete( force: false ) );
		$this->assertFalse( $this->wikiExists( 'deletewikitest' ) );

		$this->getDb()->query( 'DROP DATABASE `deletewikitest`;', __METHOD__ );
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

		$this->getDb()->query( 'DROP DATABASE `recreatewikitest`;', __METHOD__ );

		$this->assertNull( $this->createWiki( dbname: 'recreatewikitest', private: false ) );
		$this->assertTrue( $this->wikiExists( 'recreatewikitest' ) );

		$wikiManager->delete( force: true );

		$this->getDb()->query( 'DROP DATABASE `recreatewikitest`;', __METHOD__ );
	}

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
