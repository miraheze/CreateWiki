<?php

namespace Miraheze\CreateWiki\Tests\Services;

use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
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

		$this->setMwGlobals( 'wgCreateWikiDatabaseSuffix', 'test' );
		$this->setMwGlobals( 'wgCreateWikiUseClosedWikis', true );
		$this->setMwGlobals( 'wgCreateWikiUseExperimental', true );
		$this->setMwGlobals( 'wgCreateWikiUseInactiveWikis', true );
		$this->setMwGlobals( 'wgCreateWikiUsePrivateWikis', true );

		$db = $this->getServiceContainer()->getDatabaseFactory()->create( 'mysql', [
			'host' => $GLOBALS['wgDBserver'],
			'user' => 'root',
		] );

		$db->begin();
		$db->query( "GRANT ALL PRIVILEGES ON `remotewikifactorytest`.* TO 'wikiuser'@'localhost';" );
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
	}

	/**
	 * @return RemoteWikiFactory
	 */
	public function getFactoryService(): RemoteWikiFactory {
		return $this->getServiceContainer()->get( 'RemoteWikiFactory' );
	}

	/**
	 * @return WikiManagerFactory
	 */
	public function getWikiManagerFactory(): WikiManagerFactory {
		return $this->getServiceContainer()->get( 'WikiManagerFactory' );
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
	 * @covers ::commit
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

	/**
	 * @param string $dbname
	 */
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
