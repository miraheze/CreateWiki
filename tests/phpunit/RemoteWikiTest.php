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
	 */
	public function testGetSitename() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertSame( 'TestWiki', $remoteWiki->getSitename() );
	}

	/**
	 * @covers ::setSitename
	 */
	public function testSetSitename() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$remoteWiki->setSitename( 'TestWiki_New' );
		$remoteWiki->commit();

		$this->assertSame( 'TestWiki_New', $remoteWiki->getSitename() );

		$remoteWiki->setSitename( 'TestWiki' );
		$remoteWiki->commit();
	}

	/**
	 * @covers ::getLanguage
	 */
	public function testGetLanguage() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertSame( 'en', $remoteWiki->getLanguage() );
	}

	/**
	 * @covers ::setLanguage
	 */
	public function testSetLanguage() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$remoteWiki->setLanguage( 'es' );
		$remoteWiki->commit();

		$this->assertSame( 'es', $remoteWiki->getLanguage() );

		$remoteWiki->setLanguage( 'en' );
		$remoteWiki->commit();
	}

	/**
	 * @covers ::getCategory
	 */
	public function testGetCategory() {
		$remoteWiki = new RemoteWiki( 'remotewikitest' );

		$this->assertSame( 'uncategorised', $remoteWiki->getCategory() );
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
