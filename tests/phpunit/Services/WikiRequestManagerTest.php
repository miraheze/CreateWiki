<?php

namespace Miraheze\CreateWiki\Tests\Services;

use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Services\WikiRequestManager
 */
class WikiRequestManagerTest extends MediaWikiIntegrationTestCase {

	public function addDBDataOnce(): void {
		$this->setMwGlobals( MainConfigNames::VirtualDomainsMapping, [
			'virtual-createwiki-central' => [ 'db' => 'wikidb' ],
		] );

		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
		$dbw = $databaseUtils->getCentralWikiPrimaryDB();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'cw_requests' )
			->ignore()
			->row( [
				'cw_comment' => 'test',
				'cw_dbname' => 'testwikidb',
				'cw_language' => 'en',
				'cw_private' => 0,
				'cw_status' => 'inreview',
				'cw_sitename' => 'Test Wiki',
				'cw_timestamp' => $dbw->timestamp(),
				'cw_url' => 'test.example.org',
				'cw_user' => $this->getTestUser()->getUser()->getId(),
				'cw_category' => 'uncategorised',
				'cw_visibility' => WikiRequestManager::VISIBILITY_PUBLIC,
				'cw_bio' => 0,
				'cw_extra' => '[]',
			] )
			->caller( __METHOD__ )
			->execute();
	}

	private function getWikiRequestManager( int $id ): WikiRequestManager {
		$manager = $this->getServiceContainer()->getService( 'WikiRequestManager' );
		$manager->loadFromID( $id );

		return $manager;
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$manager = $this->getServiceContainer()->getService( 'WikiRequestManager' );
		$this->assertInstanceOf( WikiRequestManager::class, $manager );
	}

	/**
	 * @covers ::loadFromID
	 */
	public function testLoadFromID() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertInstanceOf( WikiRequestManager::class, $manager );
	}

	/**
	 * @covers ::exists
	 */
	public function testExists() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertTrue( $manager->exists() );

		$manager = $this->getWikiRequestManager( id: 2 );
		$this->assertFalse( $manager->exists() );
	}

	/**
	 * @covers ::isDuplicateRequest
	 */
	public function testIsDuplicateRequest() {
		$manager = $this->getWikiRequestManager( id: 1 );

		$this->assertTrue( $manager->isDuplicateRequest( 'Test Wiki' ) );
		$this->assertFalse( $manager->isDuplicateRequest( 'New Wiki' ) );
	}

	/**
	 * @covers ::getComments
	 */
	public function testGetComments() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertArrayEquals( [], $manager->getComments() );
	}

	/**
	 * @covers ::addComment
	 */
	public function testAddComment() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$manager->addComment(
			comment: 'Test',
			user: $this->getTestUser()->getUser(),
			log: false,
			type: 'comment',
			// Use all involved users
			notifyUsers: []
		);

		$this->assertCount( 1, $manager->getComments() );
	}

	/**
	 * @covers ::getRequestHistory
	 */
	public function testGetRequestHistory() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertArrayEquals( [], $manager->getRequestHistory() );
	}

	/**
	 * @covers ::addRequestHistory
	 */
	public function testAddRequestHistory() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$manager->addRequestHistory(
			action: 'test',
			details: 'Request history test',
			user: $this->getTestUser()->getUser()
		);

		$this->assertCount( 1, $manager->getRequestHistory() );
	}

	/**
	 * @covers ::getID
	 */
	public function testGetID() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame( 1, $manager->getID() );
	}

	/**
	 * @covers ::getDBname
	 */
	public function testGetDBname() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame( 'testwikidb', $manager->getDBname() );
	}

	/**
	 * @covers ::getVisibility
	 */
	public function testGetVisibility() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame(
			WikiRequestManager::VISIBILITY_PUBLIC,
			$manager->getVisibility()
		);
	}

	/**
	 * @covers ::getStatus
	 */
	public function testGetStatus() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame( 'inreview', $manager->getStatus() );
	}

	/**
	 * @covers ::getSitename
	 */
	public function testGetSitename() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame( 'Test Wiki', $manager->getSitename() );
	}

	/**
	 * @covers ::getLanguage
	 */
	public function testGetLanguage() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame( 'en', $manager->getLanguage() );
	}

	/**
	 * @covers ::getUrl
	 */
	public function testGetUrl() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame( 'test.example.org', $manager->getUrl() );
	}

	/**
	 * @covers ::getCategory
	 */
	public function testGetCategory() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame( 'uncategorised', $manager->getCategory() );
	}

	/**
	 * @covers ::isPrivate
	 */
	public function testIsPrivate() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertFalse( $manager->isPrivate() );
	}

	/**
	 * @covers ::isBio
	 */
	public function testIsBio() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertFalse( $manager->isBio() );
	}

	/**
	 * @covers ::isLocked
	 */
	public function testIsLocked() {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertFalse( $manager->isLocked() );
	}
}
