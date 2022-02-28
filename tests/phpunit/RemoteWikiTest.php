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

	private $remoteWiki;

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

		$this->remoteWiki = new RemoteWiki( 'remotewikitest' );
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
	 */
	public function testGetSitename() {
		$this->assertSame( 'TestWiki', $this->remoteWiki->getSitename() );
	}

	/**
	 * @covers ::setSitename
	 * @doesNotPerformAssertions
	 */
	public function testSetSitename() {
		$this->remoteWiki->setSitename( 'TestWiki_New' );
	}

	/**
	 * @covers ::getLanguage
	 */
	public function testGetLanguage() {
		$this->assertSame( 'en', $this->remoteWiki->getLanguage() );
	}

	/**
	 * @covers ::setLanguage
	 * @doesNotPerformAssertions
	 */
	public function testSetLanguage() {
		$this->remoteWiki->setLanguage( 'qqx' );
	}

	/**
	 * @covers ::isInactive
	 */
	public function testIsInactive() {
		$this->assertFalse( (bool)$this->remoteWiki->isInactive() );
	}

	/**
	 * @covers ::markInactive
	 * @doesNotPerformAssertions
	 */
	public function testMarkInactive() {
		$this->remoteWiki->markInactive();
	}

	/**
	 * @covers ::markActive
	 * @depends testCommit
	 * @doesNotPerformAssertions
	 */
	public function testMarkActive() {
		$this->remoteWiki->markActive();
	}

	/**
	 * @covers ::isInactiveExempt
	 */
	public function testIsInactiveExempt() {
		$this->assertFalse( (bool)$this->remoteWiki->isInactiveExempt() );
	}

	/**
	 * @covers ::getInactiveExemptReason
	 */
	public function testGetInactiveExemptReason() {
		$this->assertFalse( (bool)$this->remoteWiki->getInactiveExemptReason() );
	}

	/**
	 * @covers ::isPrivate
	 */
	public function testIsPrivate() {
		$this->assertFalse( (bool)$this->remoteWiki->isPrivate() );
	}

	/**
	 * @covers ::markPrivate
	 * @doesNotPerformAssertions
	 */
	public function testMarkPrivate() {
		$this->remoteWiki->markPrivate();
	}

	/**
	 * @covers ::markPublic
	 * @depends testCommit
	 * @doesNotPerformAssertions
	 */
	public function testMarkPublic() {
		$this->remoteWiki->markPublic();
	}

	/**
	 * @covers ::isClosed
	 */
	public function testIsClosed() {
		$this->assertFalse( (bool)$this->remoteWiki->isClosed() );
	}

	/**
	 * @covers ::isDeleted
	 */
	public function testIsDeleted() {
		$this->assertFalse( (bool)$this->remoteWiki->isDeleted() );
	}

	/**
	 * @covers ::isLocked
	 */
	public function testIsLocked() {
		$this->assertFalse( (bool)$this->remoteWiki->isLocked() );
	}

	/**
	 * @covers ::lock
	 * @doesNotPerformAssertions
	 */
	public function testLock() {
		$this->remoteWiki->lock();
	}

	/**
	 * @covers ::getCategory
	 */
	public function testGetCategory() {
		$this->assertSame( 'uncategorised', $this->remoteWiki->getCategory() );
	}

	/**
	 * @covers ::setCategory
	 * @doesNotPerformAssertions
	 */
	public function testSetCategory() {
		$this->remoteWiki->setCategory( 'test' );
	}

	/**
	 * @covers ::isExperimental
	 */
	public function testIsExperimental() {
		$this->assertFalse( (bool)$this->remoteWiki->isExperimental() );
	}

	/**
	 * @covers ::commit
	 * @depends testLock
	 * @depends testSetCategory
	 * @depends testSetLanguage
	 * @depends testSetSitename
	 * @depends testMarkInactive
	 * @depends testMarkPrivate
	 */
	public function testCommit() {
		$this->remoteWiki->commit();

		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertTrue( (bool)$remoteWiki->isLocked() );
		$this->assertTrue( (bool)$remoteWiki->isPrivate() );
		$this->assertTrue( (bool)$remoteWiki->isInactive() );
		$this->assertSame( 'test', $remoteWiki->getCategory() );
		$this->assertSame( 'qqx', $remoteWiki->getLanguage() );
		$this->assertSame( 'TestWiki_New', $remoteWiki->getSitename() );
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
