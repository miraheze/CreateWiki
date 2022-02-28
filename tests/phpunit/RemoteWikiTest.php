<?php

namespace Miraheze\CreateWiki\Tests;

use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\RemoteWiki;
use Miraheze\CreateWiki\WikiManager;
use SiteConfiguration;
use Wikimedia\Rdbms\Database;

/**
 * @group CreateWiki
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\CreateWiki\RemoteWiki
 */
class RemoteWikiTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();

		$conf = new SiteConfiguration();
		$conf->suffixes = [ 'test' ];
		$this->setMwGlobals( [
			'wgConf' => $conf,
		] );

		$db = Database::factory( 'mysql', [ 'host' => $GLOBALS['wgDBserver'], 'user' => 'root' ] );

		$db->begin();
		$db->query( "GRANT ALL PRIVILEGES ON `remotewikitest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "FLUSH PRIVILEGES;" );
		$db->commit();
	}

	/**
	 * @covers ::getDBname
	 */
	public function testGetDBname() {
		$this->createWiki( 'remotewikitest' );

		$remoteWiki = new RemoteWiki( 'remotewikitest' );
		$this->assertSame( 'remotewikitest', $remoteWiki->getDBname() );
	}

	/**
	 * @covers ::getSitename
	 * @covers ::setSitename
	 */
	public function testSetSitename() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertSame( 'TestWiki', $remoteWiki->getSitename() );

		$remoteWiki->setSitename( 'TestWiki_New' );
		$this->assertSame( 'TestWiki_New', $remoteWiki->getSitename() );
	}

	/**
	 * @covers ::getLanguage
	 * @covers ::setLanguage
	 */
	public function testSetLanguage() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertSame( 'en', $remoteWiki->getLanguage() );

		$remoteWiki->setLanguage( 'qqx' );
		$this->assertSame( 'qqx', $remoteWiki->getLanguage() );
	}

	/**
	 * @covers ::isInactive
	 * @covers ::markActive
	 * @covers ::markInactive
	 */
	public function testMarkInactive() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertFalse( (bool)$remoteWiki->isInactive() );

		$remoteWiki->markInactive();
		$this->assertTrue( (bool)$remoteWiki->isInactive() );

		$remoteWiki->markActive();
		$this->assertFalse( (bool)$remoteWiki->isInactive() );
	}

	/**
	 * @covers ::isInactiveExempt
	 */
	public function testIsInactiveExempt() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertFalse( (bool)$remoteWiki->isInactiveExempt() );
	}

	/**
	 * @covers ::getInactiveExemptReason
	 */
	public function testGetInactiveExemptReason() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertFalse( (bool)$remoteWiki->getInactiveExemptReason() );
	}

	/**
	 * @covers ::isPrivate
	 * @covers ::markPrivate
	 * @covers ::markPublic
	 */
	public function testMarkPrivate() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertFalse( (bool)$remoteWiki->isPrivate() );

		$remoteWiki->markPrivate();
		$this->assertTrue( (bool)$remoteWiki->isPrivate() );

		$remoteWiki->markPublic();
		$this->assertFalse( (bool)$remoteWiki->isPrivate() );
	}

	/**
	 * @covers ::isClosed
	 * @covers ::markClosed
	 */
	public function testMarkClosed() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertFalse( (bool)$remoteWiki->isClosed() );

		$remoteWiki->markClosed();
		$this->assertTrue( (bool)$remoteWiki->isClosed() );

		$remoteWiki->markActive();
		$this->assertFalse( (bool)$remoteWiki->isClosed() );
	}

	/**
	 * @covers ::delete
	 * @covers ::isDeleted
	 * @covers ::undelete
	 */
	public function testDelete() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertFalse( (bool)$remoteWiki->isDeleted() );

		$remoteWiki->delete();
		$this->assertTrue( (bool)$remoteWiki->isDeleted() );

		$remoteWiki->undelete();
		$this->assertFalse( (bool)$remoteWiki->isDeleted() );
	}

	/**
	 * @covers ::isLocked
	 * @covers ::lock
	 * @covers ::unlock
	 */
	public function testLock() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertFalse( (bool)$remoteWiki->isLocked() );

		$remoteWiki->lock();
		$this->assertTrue( (bool)$remoteWiki->isLocked() );

		$remoteWiki->unlock();
		$this->assertFalse( (bool)$remoteWiki->isLocked() );
	}

	/**
	 * @covers ::getCategory
	 * @covers ::setCategory
	 */
	public function testSetCategory() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertSame( 'uncategorised', $remoteWiki->getCategory() );

		$remoteWiki->setCategory( 'test' );
		$this->assertSame( 'test', $remoteWiki->getCategory() );
	}

	/**
	 * @covers ::isExperimental
	 * @covers ::markExperimental
	 * @covers ::unMarkExperimental
	 */
	public function testMarkExperimental() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertFalse( (bool)$remoteWiki->isExperimental() );

		$remoteWiki->markExperimental();
		$this->assertTrue( (bool)$remoteWiki->isExperimental() );

		$remoteWiki->unMarkExperimental();
		$this->assertFalse( (bool)$remoteWiki->isExperimental() );
	}

	/**
	 * @param string $dbname
	 */
	private function createWiki( string $dbname ) {
		$testUser = $this->getTestUser()->getUser();
		$testSysop = $this->getTestSysop()->getUser();

		$wikiManager = new WikiManager( $dbname );
		$wikiManager->create( 'TestWiki', 'en', 0, 'uncategorised', $testUser->getName(), $testSysop->getName(), 'Test' );
	}
}
