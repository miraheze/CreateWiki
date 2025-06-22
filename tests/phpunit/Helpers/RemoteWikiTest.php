<?php

namespace Miraheze\CreateWiki\Tests\Helpers;

use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Exceptions\MissingWikiError;
use Miraheze\CreateWiki\Helpers\RemoteWiki;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Helpers\RemoteWiki
 */
class RemoteWikiTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			ConfigNames::DatabaseSuffix => 'test',
			ConfigNames::UseClosedWikis => true,
			ConfigNames::UseExperimental => true,
			ConfigNames::UseInactiveWikis => true,
			ConfigNames::UsePrivateWikis => true,
		] );

		$db = $this->getServiceContainer()->getDatabaseFactory()->create( 'mysql', [
			'host' => $this->getConfVar( MainConfigNames::DBserver ),
			'user' => 'root',
		] );

		if ( $db === null ) {
			return;
		}

		$db->begin();
		$db->query( "GRANT ALL PRIVILEGES ON `remotewikitest`.* TO 'wikiuser'@'localhost';" );
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

	public function getFactoryService(): RemoteWikiFactory {
		return $this->getServiceContainer()->get( 'RemoteWikiFactory' );
	}

	public function getWikiManagerFactory(): WikiManagerFactory {
		return $this->getServiceContainer()->get( 'WikiManagerFactory' );
	}

	/**
	 * @covers \Miraheze\CreateWiki\Services\RemoteWikiFactory::__construct
	 */
	public function testFactoryConstructor(): void {
		$this->assertInstanceOf( RemoteWikiFactory::class, $this->getFactoryService() );
	}

	/**
	 * @covers ::__construct
	 * @covers \Miraheze\CreateWiki\Services\RemoteWikiFactory::newInstance
	 */
	public function testNewFactoryInstance(): void {
		$factory = $this->getFactoryService()->newInstance( 'wikidb' );
		$this->assertInstanceOf( RemoteWiki::class, $factory );
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructorException(): void {
		$this->expectException( MissingWikiError::class );
		$this->expectExceptionMessage( 'The wiki \'missingwiki\' does not exist.' );
		$this->getFactoryService()->newInstance( 'missingwiki' );
	}

	/**
	 * @covers ::getCreationDate
	 */
	public function testGetCreationDate(): void {
		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$timestamp = $this->getDb()->timestamp();
		$this->createWiki( 'remotewikitest' );

		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertSame( $timestamp, $remoteWiki->getCreationDate() );
	}

	/**
	 * @covers ::getDBname
	 */
	public function testGetDBname(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertSame( 'remotewikitest', $remoteWiki->getDBname() );
	}

	/**
	 * @covers ::getSitename
	 * @covers ::setSitename
	 * @covers ::trackChange
	 */
	public function testSetSitename(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertSame( 'TestWiki', $remoteWiki->getSitename() );

		$remoteWiki->setSitename( 'TestWiki_New' );
		$remoteWiki->commit();

		$this->assertSame( 'TestWiki_New', $remoteWiki->getSitename() );
	}

	/**
	 * @covers ::getLanguage
	 * @covers ::setLanguage
	 * @covers ::trackChange
	 */
	public function testSetLanguage(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertSame( 'en', $remoteWiki->getLanguage() );

		$remoteWiki->setLanguage( 'qqx' );
		$remoteWiki->commit();

		$this->assertSame( 'qqx', $remoteWiki->getLanguage() );
	}

	/**
	 * @covers ::getInactiveTimestamp
	 * @covers ::isInactive
	 * @covers ::markActive
	 * @covers ::markInactive
	 * @covers ::trackChange
	 */
	public function testMarkInactive(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertFalse( $remoteWiki->isInactive() );

		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );
		$timestamp = $this->getDb()->timestamp();

		$remoteWiki->markInactive();
		$remoteWiki->commit();

		$this->assertTrue( $remoteWiki->isInactive() );
		$this->assertSame( $timestamp, $remoteWiki->getInactiveTimestamp() );

		$remoteWiki->markActive();
		$remoteWiki->commit();

		$this->assertFalse( $remoteWiki->isInactive() );
	}

	/**
	 * @covers ::isInactiveExempt
	 * @covers ::markExempt
	 * @covers ::unExempt
	 * @covers ::trackChange
	 */
	public function testMarkExempt(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertFalse( $remoteWiki->isInactiveExempt() );

		$remoteWiki->markExempt();
		$remoteWiki->commit();

		$this->assertTrue( $remoteWiki->isInactiveExempt() );

		$remoteWiki->unExempt();
		$remoteWiki->commit();

		$this->assertFalse( $remoteWiki->isInactiveExempt() );
	}

	/**
	 * @covers ::getInactiveExemptReason
	 * @covers ::setInactiveExemptReason
	 * @covers ::trackChange
	 */
	public function testSetInactiveExemptReason(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertNull( $remoteWiki->getInactiveExemptReason() );

		$remoteWiki->setInactiveExemptReason( 'test' );
		$remoteWiki->commit();

		$this->assertSame( 'test', $remoteWiki->getInactiveExemptReason() );
	}

	/**
	 * @covers ::isPrivate
	 * @covers ::markPrivate
	 * @covers ::markPublic
	 * @covers ::trackChange
	 */
	public function testMarkPrivate(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertFalse( $remoteWiki->isPrivate() );

		$remoteWiki->markPrivate();
		$remoteWiki->commit();

		$this->assertTrue( $remoteWiki->isPrivate() );

		$remoteWiki->markPublic();
		$remoteWiki->commit();

		$this->assertFalse( $remoteWiki->isPrivate() );
	}

	/**
	 * @covers ::getClosedTimestamp
	 * @covers ::isClosed
	 * @covers ::markClosed
	 * @covers ::trackChange
	 */
	public function testMarkClosed(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertFalse( $remoteWiki->isClosed() );

		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );
		$timestamp = $this->getDb()->timestamp();

		$remoteWiki->markClosed();
		$remoteWiki->commit();

		$this->assertTrue( $remoteWiki->isClosed() );
		$this->assertSame( $timestamp, $remoteWiki->getClosedTimestamp() );

		$remoteWiki->markActive();
		$remoteWiki->commit();

		$this->assertFalse( $remoteWiki->isClosed() );
	}

	/**
	 * @covers ::delete
	 * @covers ::getDeletedTimestamp
	 * @covers ::isDeleted
	 * @covers ::undelete
	 * @covers ::trackChange
	 */
	public function testDelete(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertFalse( $remoteWiki->isDeleted() );

		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );
		$timestamp = $this->getDb()->timestamp();

		$remoteWiki->delete();
		$remoteWiki->commit();

		$this->assertTrue( $remoteWiki->isDeleted() );
		$this->assertSame( $timestamp, $remoteWiki->getDeletedTimestamp() );

		$remoteWiki->undelete();
		$remoteWiki->commit();

		$this->assertFalse( $remoteWiki->isDeleted() );
	}

	/**
	 * @covers ::isLocked
	 * @covers ::lock
	 * @covers ::unlock
	 * @covers ::trackChange
	 */
	public function testLock(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertFalse( $remoteWiki->isLocked() );

		$remoteWiki->lock();
		$remoteWiki->commit();

		$this->assertTrue( $remoteWiki->isLocked() );

		$remoteWiki->unlock();
		$remoteWiki->commit();

		$this->assertFalse( $remoteWiki->isLocked() );
	}

	/**
	 * @covers ::getCategory
	 * @covers ::setCategory
	 * @covers ::trackChange
	 */
	public function testSetCategory(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertSame( 'uncategorised', $remoteWiki->getCategory() );

		$remoteWiki->setCategory( 'test' );
		$remoteWiki->commit();

		$this->assertSame( 'test', $remoteWiki->getCategory() );
	}

	/**
	 * @covers ::getServerName
	 * @covers ::setServerName
	 * @covers ::trackChange
	 */
	public function testSetServerName(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertSame( '', $remoteWiki->getServerName() );

		$remoteWiki->setServerName( 'http://127.0.0.1' );
		$remoteWiki->commit();

		$this->assertSame( 'http://127.0.0.1', $remoteWiki->getServerName() );
	}

	/**
	 * @covers ::getDBCluster
	 * @covers ::setDBCluster
	 * @covers ::trackChange
	 */
	public function testSetDBCluster(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertSame( 'c1', $remoteWiki->getDBCluster() );

		$remoteWiki->setDBCluster( 'c2' );
		$remoteWiki->commit();

		$this->assertSame( 'c2', $remoteWiki->getDBCluster() );
	}

	/**
	 * @covers ::isExperimental
	 * @covers ::markExperimental
	 * @covers ::unMarkExperimental
	 * @covers ::trackChange
	 */
	public function testMarkExperimental(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertFalse( $remoteWiki->isExperimental() );

		$remoteWiki->markExperimental();
		$remoteWiki->commit();

		$this->assertTrue( $remoteWiki->isExperimental() );

		$remoteWiki->unMarkExperimental();
		$remoteWiki->commit();

		$this->assertFalse( $remoteWiki->isExperimental() );
	}

	/**
	 * @covers ::getExtraFieldData
	 * @covers ::setExtraFieldData
	 * @covers ::trackChange
	 */
	public function testSetExtraFieldData(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertNull( $remoteWiki->getExtraFieldData( 'test', default: null ) );

		$remoteWiki->setExtraFieldData( 'test', 'valid', default: null );
		$remoteWiki->commit();

		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertSame( 'valid', $remoteWiki->getExtraFieldData( 'test', default: null ) );

		// Test if there are no changes
		$remoteWiki->setExtraFieldData( 'test', 'valid', default: null );
		$remoteWiki->commit();

		$this->assertSame( 'valid', $remoteWiki->getExtraFieldData( 'test', default: null ) );

		// Test invalid data
		$remoteWiki->setExtraFieldData( 'test', "\xB1\x31", default: null );
		$remoteWiki->commit();

		$this->assertSame( 'valid', $remoteWiki->getExtraFieldData( 'test', default: null ) );

		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertNull( $remoteWiki->getExtraFieldData( 'test2', default: null ) );

		$remoteWiki->setExtraFieldData( 'test', 'validnew', default: null );
		$remoteWiki->setExtraFieldData( 'test2', 'valid2', default: null );
		$remoteWiki->commit();

		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertSame( 'validnew', $remoteWiki->getExtraFieldData( 'test', default: null ) );
		$this->assertSame( 'valid2', $remoteWiki->getExtraFieldData( 'test2', default: null ) );
	}

	/**
	 * @covers ::disableResetDatabaseLists
	 */
	public function testDisableResetDatabaseLists(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$remoteWiki->disableResetDatabaseLists();
		$this->assertFalse(
			TestingAccessWrapper::newFromObject( $remoteWiki )->resetDatabaseLists
		);
	}

	/**
	 * @covers ::getErrors
	 */
	public function testGetErrors(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertArrayEquals( [], $remoteWiki->getErrors() );
	}

	/**
	 * @covers ::getLogAction
	 * @covers ::setLogAction
	 */
	public function testSetLogAction(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertSame( 'settings', $remoteWiki->getLogAction() );

		$remoteWiki->setLogAction( 'test' );
		$this->assertSame( 'test', $remoteWiki->getLogAction() );
	}

	/**
	 * @covers ::addLogParam
	 * @covers ::getLogParams
	 */
	public function testAddLogParam(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertArrayEquals( [], $remoteWiki->getLogParams() );

		$remoteWiki->addLogParam( 'test', true );
		$this->assertTrue( $remoteWiki->getLogParams()['test'] );
	}

	/**
	 * @covers ::commit
	 * @covers ::hasChanges
	 */
	public function testCommit(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertSame( 'TestWiki_New', $remoteWiki->getSitename() );

		$remoteWiki->setSitename( 'TestWiki' );
		$remoteWiki->commit();

		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );

		$this->assertSame( 'http://127.0.0.1', $remoteWiki->getServerName() );
		$this->assertSame( 'test', $remoteWiki->getInactiveExemptReason() );
		$this->assertSame( 'TestWiki', $remoteWiki->getSitename() );
		$this->assertSame( 'test', $remoteWiki->getCategory() );
		$this->assertSame( 'qqx', $remoteWiki->getLanguage() );
		$this->assertSame( 'c2', $remoteWiki->getDBCluster() );
	}

	/**
	 * @covers ::commit
	 * @covers ::hasChanges
	 */
	public function testCommitNoChanges(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertSame( 'TestWiki', $remoteWiki->getSitename() );

		$remoteWiki->setSitename( 'TestWiki' );
		$remoteWiki->commit();

		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikitest' );
		$this->assertSame( 'TestWiki', $remoteWiki->getSitename() );
	}

	private function createWiki( string $dbname ): void {
		$testUser = $this->getTestUser()->getUser();
		$testSysop = $this->getTestSysop()->getUser();

		$wikiManager = $this->getWikiManagerFactory()->newInstance( $dbname );
		$wikiManager->create(
			'TestWiki', 'en', false, 'uncategorised',
			$testUser->getName(), $testSysop->getName(),
			'Test', []
		);
	}
}
