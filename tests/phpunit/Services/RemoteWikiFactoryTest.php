<?php

namespace Miraheze\CreateWiki\Tests\Services;

use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Exceptions\MissingWikiError;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Services\RemoteWikiFactory
 */
class RemoteWikiFactoryTest extends MediaWikiIntegrationTestCase {

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

		$db->begin();
		$db->query( "GRANT ALL PRIVILEGES ON `remotewikifactorytest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "FLUSH PRIVILEGES;" );
		$db->commit();
	}

	public function addDBDataOnce(): void {
		$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
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
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$this->assertInstanceOf( RemoteWikiFactory::class, $this->getFactoryService() );
	}

	/**
	 * @covers ::newInstance
	 */
	public function testNewInstanceException(): void {
		$this->expectException( MissingWikiError::class );
		$this->expectExceptionMessage( 'The wiki \'missingwiki\' does not exist.' );
		$this->getFactoryService()->newInstance( 'missingwiki' );
	}

	/**
	 * @covers ::newInstance
	 */
	public function testNewInstance(): void {
		$factory = $this->getFactoryService()->newInstance( 'wikidb' );
		$this->assertInstanceOf( RemoteWikiFactory::class, $factory );
	}

	/**
	 * @covers ::getCreationDate
	 */
	public function testGetCreationDate(): void {
		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$timestamp = $this->db->timestamp();
		$this->createWiki( 'remotewikifactorytest' );

		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );
		$this->assertSame( $timestamp, $remoteWiki->getCreationDate() );
	}

	/**
	 * @covers ::getDBname
	 */
	public function testGetDBname(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );
		$this->assertSame( 'remotewikifactorytest', $remoteWiki->getDBname() );
	}

	/**
	 * @covers ::getSitename
	 * @covers ::setSitename
	 * @covers ::trackChange
	 */
	public function testSetSitename(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

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
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

		$this->assertSame( 'en', $remoteWiki->getLanguage() );

		$remoteWiki->setLanguage( 'qqx' );
		$remoteWiki->commit();

		$this->assertSame( 'qqx', $remoteWiki->getLanguage() );
	}

	/**
	 * @covers ::isInactive
	 * @covers ::markActive
	 * @covers ::markInactive
	 * @covers ::trackChange
	 */
	public function testMarkInactive(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

		$this->assertFalse( $remoteWiki->isInactive() );

		$remoteWiki->markInactive();
		$remoteWiki->commit();

		$this->assertTrue( $remoteWiki->isInactive() );

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
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

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
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

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
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

		$this->assertFalse( $remoteWiki->isPrivate() );

		$remoteWiki->markPrivate();
		$remoteWiki->commit();

		$this->assertTrue( $remoteWiki->isPrivate() );

		$remoteWiki->markPublic();
		$remoteWiki->commit();

		$this->assertFalse( $remoteWiki->isPrivate() );
	}

	/**
	 * @covers ::isClosed
	 * @covers ::markClosed
	 * @covers ::trackChange
	 */
	public function testMarkClosed(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

		$this->assertFalse( $remoteWiki->isClosed() );

		$remoteWiki->markClosed();
		$remoteWiki->commit();

		$this->assertTrue( $remoteWiki->isClosed() );

		$remoteWiki->markActive();
		$remoteWiki->commit();

		$this->assertFalse( $remoteWiki->isClosed() );
	}

	/**
	 * @covers ::delete
	 * @covers ::isDeleted
	 * @covers ::undelete
	 * @covers ::trackChange
	 */
	public function testDelete(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

		$this->assertFalse( $remoteWiki->isDeleted() );

		$remoteWiki->delete();
		$remoteWiki->commit();

		$this->assertTrue( $remoteWiki->isDeleted() );

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
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

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
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

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
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

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
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

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
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

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
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );
		$this->assertNull( $remoteWiki->getExtraFieldData( 'test', default: null ) );

		$remoteWiki->setExtraFieldData( 'test', 'valid', default: null );
		$remoteWiki->commit();

		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );
		$this->assertSame( 'valid', $remoteWiki->getExtraFieldData( 'test', default: null ) );

		// Test if there are no changes
		$remoteWiki->setExtraFieldData( 'test', 'valid', default: null );
		$remoteWiki->commit();

		$this->assertSame( 'valid', $remoteWiki->getExtraFieldData( 'test', default: null ) );

		// Test invalid data
		$remoteWiki->setExtraFieldData( 'test', "\xB1\x31", default: null );
		$remoteWiki->commit();

		$this->assertSame( 'valid', $remoteWiki->getExtraFieldData( 'test', default: null ) );

		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );
		$this->assertNull( $remoteWiki->getExtraFieldData( 'test2', default: null ) );

		$remoteWiki->setExtraFieldData( 'test', 'validnew', default: null );
		$remoteWiki->setExtraFieldData( 'test2', 'valid2', default: null );
		$remoteWiki->commit();

		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );
		$this->assertSame( 'validnew', $remoteWiki->getExtraFieldData( 'test', default: null ) );
		$this->assertSame( 'valid2', $remoteWiki->getExtraFieldData( 'test2', default: null ) );
	}

	/**
	 * @covers ::disableResetDatabaseLists
	 */
	public function testDisableResetDatabaseLists(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );
		$remoteWiki->disableResetDatabaseLists();
		$this->assertFalse(
			TestingAccessWrapper::newFromObject( $remoteWiki )->resetDatabaseLists
		);
	}

	/**
	 * @covers ::getErrors
	 */
	public function testGetErrors(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );
		$this->assertArrayEquals( [], $remoteWiki->getErrors() );
	}

	/**
	 * @covers ::getLogAction
	 * @covers ::setLogAction
	 */
	public function testSetLogAction(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );
		$this->assertSame( 'settings', $remoteWiki->getLogAction() );

		$remoteWiki->setLogAction( 'test', true );
		$this->assertSame( 'test', $remoteWiki->getLogAction() );
	}

	/**
	 * @covers ::addLogParam
	 * @covers ::getLogParams
	 */
	public function testAddLogParam(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );
		$this->assertArrayEquals( [], $remoteWiki->getLogParams() );

		$remoteWiki->addLogParam( 'test', true );
		$this->assertTrue( $remoteWiki->getLogParams()['test'] );
	}

	/**
	 * @covers ::commit
	 * @covers ::hasChanges
	 */
	public function testCommit(): void {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

		$this->assertSame( 'http://127.0.0.1', $remoteWiki->getServerName() );
		$this->assertSame( 'test', $remoteWiki->getInactiveExemptReason() );
		$this->assertSame( 'TestWiki_New', $remoteWiki->getSitename() );
		$this->assertSame( 'test', $remoteWiki->getCategory() );
		$this->assertSame( 'qqx', $remoteWiki->getLanguage() );
		$this->assertSame( 'c2', $remoteWiki->getDBCluster() );
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
