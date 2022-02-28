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
	 * @covers ::markInactive
	 */
	public function testMarkInactive() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertFalse( (bool)$remoteWiki->isInactive() );

		$remoteWiki->markInactive();

		$this->assertTrue( (bool)$remoteWiki->isInactive() );
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
	 */
	public function testMarkPrivate() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertFalse( (bool)$remoteWiki->isPrivate() );

		$remoteWiki->markPrivate();

		$this->assertTrue( (bool)$remoteWiki->isPrivate() );
	}

	/**
	 * @covers ::isClosed
	 */
	public function testIsClosed() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertFalse( (bool)$remoteWiki->isClosed() );
	}

	/**
	 * @covers ::isDeleted
	 */
	public function testIsDeleted() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertFalse( (bool)$remoteWiki->isDeleted() );
	}

	/**
	 * @covers ::isLocked
	 * @covers ::lock
	 */
	public function testLock() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$remoteWiki->lock();

		$this->assertTrue( (bool)$remoteWiki->isLocked() );
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
	 */
	public function testIsExperimental() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

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
