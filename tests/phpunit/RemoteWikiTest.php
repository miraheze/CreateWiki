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
		$db->query( "GRANT ALL PRIVILEGES ON `createwikitest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "FLUSH PRIVILEGES;" );
		$db->commit();

		$this->createWiki( 'createwikitest' );
	}

	/**
	 * @covers ::getDBname
	 */
	public function testGetDBname() {
		$remoteWiki = new RemoteWiki( 'createwikitest' );

		$this->assertSame( 'createwikitest', $remoteWiki->getDBname() );
	}

	/**
	 * @covers ::getSitename
	 */
	public function testGetSitename() {
		$remoteWiki = new RemoteWiki( 'createwikitest' );

		$this->assertSame( 'TestWiki', $remoteWiki->getSitename() );
	}

	/**
	 * @covers ::getLanguage
	 */
	public function testGetLanguage() {
		$remoteWiki = new RemoteWiki( 'createwikitest' );

		$this->assertSame( 'en', $remoteWiki->getLanguage() );
	}

	/**
	 * @covers ::getCategory
	 */
	public function testGetCategory() {
		$remoteWiki = new RemoteWiki( 'createwikitest' );

		$this->assertSame( 'uncategorised', $remoteWiki->getCategory() );
	}

	/**
	 * @param string $dbname
	 * @return mixed
	 */
	private function createWiki( string $dbname ) {
		$testUser = $this->getTestUser()->getUser();
		$testSysop = $this->getTestSysop()->getUser();

		$wikiManager = new WikiManager( $dbname );

		if ( !$wikiManager->exists ) {
			$wikiManager->create( 'TestWiki', 'en', 0, 'uncategorised', $testUser->getName(), $testSysop->getName(), 'Test' );
		}
	}
}
