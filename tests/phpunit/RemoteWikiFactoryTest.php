<?php

namespace Miraheze\CreateWiki\Tests;

use MediaWiki\Config\SiteConfiguration;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\RemoteWikiFactory;
use Miraheze\CreateWiki\WikiManager;
use Wikimedia\Rdbms\DBQueryError;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group CreateWiki
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\CreateWiki\RemoteWikiFactory
 */
class RemoteWikiFactoryTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$conf = new SiteConfiguration();
		$conf->suffixes = [ 'test' ];

		$this->setMwGlobals( 'wgConf', $conf );
		$this->setMwGlobals( 'wgCreateWikiUseClosedWikis', true );
		$this->setMwGlobals( 'wgCreateWikiUseExperimental', true );
		$this->setMwGlobals( 'wgCreateWikiUseInactiveWikis', true );
		$this->setMwGlobals( 'wgCreateWikiUsePrivateWikis', true );

		$db = MediaWikiServices::getInstance()->getDatabaseFactory()->create( 'mysql', [
			'host' => $GLOBALS['wgDBserver'],
			'user' => 'root',
		] );

		$db->begin();
		$db->query( "GRANT ALL PRIVILEGES ON `remotewikifactorytest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "FLUSH PRIVILEGES;" );
		$db->commit();
	}

	public function addDBDataOnce(): void {
		try {
			$dbw = MediaWikiServices::getInstance()
				->getDBLoadBalancer()
				->getMaintenanceConnectionRef( DB_PRIMARY );

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
	 * @return RemoteWikiFactory
	 */
	public function getFactoryService(): RemoteWikiFactory {
		return $this->getServiceContainer()->get( 'RemoteWikiFactory' );
	}

	/**
	 * @covers ::getCreationDate
	 */
	public function testGetCreationDate() {
		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$timestamp = $this->db->timestamp();
		$this->createWiki( 'remotewikifactorytest' );

		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );
		$this->assertSame( $timestamp, $remoteWiki->getCreationDate() );
	}

	/**
	 * @covers ::getDBname
	 */
	public function testGetDBname() {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );
		$this->assertSame( 'remotewikifactorytest', $remoteWiki->getDBname() );
	}

	/**
	 * @covers ::getSitename
	 * @covers ::setSitename
	 */
	public function testSetSitename() {
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
	public function testSetLanguage() {
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
	public function testMarkInactive() {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

		$this->assertFalse( (bool)$remoteWiki->isInactive() );

		$remoteWiki->markInactive();
		$remoteWiki->commit();

		$this->assertTrue( (bool)$remoteWiki->isInactive() );

		$remoteWiki->markActive();
		$remoteWiki->commit();

		$this->assertFalse( (bool)$remoteWiki->isInactive() );
	}

	/**
	 * @covers ::isInactiveExempt
	 * @covers ::markExempt
	 * @covers ::unExempt
	 */
	public function testMarkExempt() {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

		$this->assertFalse( (bool)$remoteWiki->isInactiveExempt() );

		$remoteWiki->markExempt();
		$remoteWiki->commit();

		$this->assertTrue( (bool)$remoteWiki->isInactiveExempt() );

		$remoteWiki->unExempt();
		$remoteWiki->commit();

		$this->assertFalse( (bool)$remoteWiki->isInactiveExempt() );
	}

	/**
	 * @covers ::getInactiveExemptReason
	 * @covers ::setInactiveExemptReason
	 */
	public function testSetInactiveExemptReason() {
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
	public function testMarkPrivate() {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

		$this->assertFalse( (bool)$remoteWiki->isPrivate() );

		$remoteWiki->markPrivate();
		$remoteWiki->commit();

		$this->assertTrue( (bool)$remoteWiki->isPrivate() );

		$remoteWiki->markPublic();
		$remoteWiki->commit();

		$this->assertFalse( (bool)$remoteWiki->isPrivate() );
	}

	/**
	 * @covers ::isClosed
	 * @covers ::markClosed
	 */
	public function testMarkClosed() {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

		$this->assertFalse( (bool)$remoteWiki->isClosed() );

		$remoteWiki->markClosed();
		$remoteWiki->commit();

		$this->assertTrue( (bool)$remoteWiki->isClosed() );

		$remoteWiki->markActive();
		$remoteWiki->commit();

		$this->assertFalse( (bool)$remoteWiki->isClosed() );
	}

	/**
	 * @covers ::delete
	 * @covers ::isDeleted
	 * @covers ::undelete
	 */
	public function testDelete() {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

		$this->assertFalse( (bool)$remoteWiki->isDeleted() );

		$remoteWiki->delete();
		$remoteWiki->commit();

		$this->assertTrue( (bool)$remoteWiki->isDeleted() );

		$remoteWiki->undelete();
		$remoteWiki->commit();

		$this->assertFalse( (bool)$remoteWiki->isDeleted() );
	}

	/**
	 * @covers ::isLocked
	 * @covers ::lock
	 * @covers ::unlock
	 */
	public function testLock() {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

		$this->assertFalse( (bool)$remoteWiki->isLocked() );

		$remoteWiki->lock();
		$remoteWiki->commit();

		$this->assertTrue( (bool)$remoteWiki->isLocked() );

		$remoteWiki->unlock();
		$remoteWiki->commit();

		$this->assertFalse( (bool)$remoteWiki->isLocked() );
	}

	/**
	 * @covers ::getCategory
	 * @covers ::setCategory
	 */
	public function testSetCategory() {
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
	public function testSetServerName() {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

		$this->assertNull( $remoteWiki->getServerName() );

		$remoteWiki->setServerName( 'http://127.0.0.1' );
		$remoteWiki->commit();

		$this->assertSame( 'http://127.0.0.1', $remoteWiki->getServerName() );
	}

	/**
	 * @covers ::getDBCluster
	 * @covers ::setDBCluster
	 */
	public function testSetDBCluster() {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

		$this->assertNull( $remoteWiki->getDBCluster() );

		$remoteWiki->setDBCluster( 'c1' );
		$remoteWiki->commit();

		$this->assertSame( 'c1', $remoteWiki->getDBCluster() );
	}

	/**
	 * @covers ::isExperimental
	 * @covers ::markExperimental
	 * @covers ::unMarkExperimental
	 */
	public function testMarkExperimental() {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

		$this->assertFalse( (bool)$remoteWiki->isExperimental() );

		$remoteWiki->markExperimental();
		$remoteWiki->commit();

		$this->assertTrue( (bool)$remoteWiki->isExperimental() );

		$remoteWiki->unMarkExperimental();
		$remoteWiki->commit();

		$this->assertFalse( (bool)$remoteWiki->isExperimental() );
	}

	/**
	 * @covers ::commit
	 */
	public function testCommit() {
		$remoteWiki = $this->getFactoryService()->newInstance( 'remotewikifactorytest' );

		$this->assertSame( 'http://127.0.0.1', $remoteWiki->getServerName() );
		$this->assertSame( 'test', $remoteWiki->getInactiveExemptReason() );
		$this->assertSame( 'TestWiki_New', $remoteWiki->getSitename() );
		$this->assertSame( 'test', $remoteWiki->getCategory() );
		$this->assertSame( 'qqx', $remoteWiki->getLanguage() );
		$this->assertSame( 'c1', $remoteWiki->getDBCluster() );
	}

	/**
	 * @param string $dbname
	 */
	private function createWiki( string $dbname ) {
		$testUser = $this->getTestUser()->getUser();
		$testSysop = $this->getTestSysop()->getUser();

		$wikiManager = new WikiManager( $dbname, $this->createMock( CreateWikiHookRunner::class ) );
		$wikiManager->create( 'TestWiki', 'en', 0, 'uncategorised', $testUser->getName(), $testSysop->getName(), 'Test' );
	}
}
