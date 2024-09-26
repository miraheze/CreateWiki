<?php

namespace Miraheze\CreateWiki\Tests;

use BagOStuff;
use MediaWiki\Config\ServiceOptions;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\CreateWikiDataFactory;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use UnexpectedValueException;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * @group CreateWiki
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\CreateWiki\CreateWikiDataFactory
 */
class CreateWikiDataFactoryTest extends MediaWikiIntegrationTestCase {

	private CreateWikiDataFactory $factory;
	private BagOStuff $cache;
	private IConnectionProvider $connectionProvider;
	private CreateWikiHookRunner $hookRunner;
	private ServiceOptions $options;

	protected function setUp(): void {
		parent::setUp();

		$this->cache = $this->createMock( BagOStuff::class );
		$this->connectionProvider = $this->createMock( IConnectionProvider::class );
		$this->hookRunner = $this->createMock( CreateWikiHookRunner::class );
		$this->options = $this->createMock( ServiceOptions::class );

		// Mock required options
		$this->options->method( 'assertRequiredOptions' )
			->with( CreateWikiDataFactory::CONSTRUCTOR_OPTIONS );

		$this->options->method( 'get' )
			->willReturnMap( [
				[ 'CreateWikiCacheType', false ],
				[ 'CreateWikiCacheDirectory', '/tmp' ],
				[ 'CreateWikiDatabase', 'wiki_db' ]
			] );

		$this->factory = new CreateWikiDataFactory(
			$this->connectionProvider,
			$this->getServiceContainer()->getObjectCacheFactory(),
			$this->hookRunner,
			$this->options
		);
	}

	public function testNewInstance() {
		$this->cache->expects( $this->any() )
			->method( 'get' )
			->willReturn( 0 );

		$this->cache->expects( $this->any() )
			->method( 'makeGlobalKey' )
			->willReturn( 'CreateWiki:databases' );

		$wiki = 'examplewiki';
		$instance = $this->factory->newInstance( $wiki );

		$this->assertInstanceOf( CreateWikiDataFactory::class, $instance );
	}

	public function testSyncCacheWhenNoCacheFilesExist() {
		$this->cache->expects( $this->any() )
			->method( 'get' )
			->willReturn( 0 );

		$this->cache->expects( $this->any() )
			->method( 'makeGlobalKey' )
			->willReturn( 'CreateWiki:databases' );

		// Simulate no cache files existing
		$this->assertFalse( file_exists( '/tmp/databases.php' ) );
		$this->assertFalse( file_exists( '/tmp/examplewiki.php' ) );

		$wiki = 'examplewiki';
		$instance = $this->factory->newInstance( $wiki );
		$instance->syncCache();

		// The cache should be reset since no files existed
		$this->assertFileExists( '/tmp/databases.php' );
		$this->assertFileExists( '/tmp/examplewiki.php' );
	}

	public function testResetDatabaseLists() {
		$wiki = 'examplewiki';

		$this->hookRunner->expects( $this->once() )
			->method( 'onCreateWikiGenerateDatabaseLists' )
			->willReturn( [] );

		// We can assume the database reset function runs and doesn't throw exceptions
		$instance = $this->factory->newInstance( $wiki );
		$instance->resetDatabaseLists( isNewChanges: true );

		// Ensure the test completes without exceptions
		$this->assertTrue( true );
	}

	public function testResetWikiDataThrowsExceptionForMissingWiki() {
		$this->expectException( UnexpectedValueException::class );
		$this->expectExceptionMessage( "Wiki 'missingwiki' cannot be found." );

		$wiki = 'missingwiki';
		$instance = $this->factory->newInstance( $wiki );

		$mockDbr = $this->createMock( IReadableDatabase::class );
		$mockDbr->method( 'newSelectQueryBuilder' )
			->willReturn( $this->createMock( SelectQueryBuilder::class ) );

		$instance->resetWikiData( isNewChanges: true );
	}

	public function testDeleteWikiData() {
		$wiki = 'examplewiki';

		// Create temporary cache file
		$tmpFile = "/tmp/{$wiki}.php";
		file_put_contents( $tmpFile, "<?php\n\nreturn [];\n" );
		$this->assertFileExists( $tmpFile );

		$instance = $this->factory->newInstance( $wiki );
		$instance->deleteWikiData( $wiki );

		// The cache file should be deleted
		$this->assertFileDoesNotExist( $tmpFile );
	}
}
