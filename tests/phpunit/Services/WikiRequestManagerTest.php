<?php

namespace Miraheze\CreateWiki\Tests\Services;

use MediaWiki\MainConfigNames;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Services\WikiRequestManager
 */
class WikiRequestManagerTest extends MediaWikiIntegrationTestCase {

	private static User $user;

	public function addDBDataOnce(): void {
		self::$user = $this->getTestUser()->getUser();
		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );
		$this->overrideConfigValue( MainConfigNames::VirtualDomainsMapping, [
			'virtual-createwiki-central' => [ 'db' => 'wikidb' ],
		] );

		$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
		'@phan-var CreateWikiDatabaseUtils $databaseUtils';

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
				'cw_timestamp' => '20250303234810',
				'cw_url' => 'test.example.org',
				'cw_user' => self::$user->getId(),
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
		'@phan-var WikiRequestManager $manager';
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
	public function testLoadFromID(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertInstanceOf( WikiRequestManager::class, $manager );
	}

	/**
	 * @covers ::exists
	 */
	public function testExists(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertTrue( $manager->exists() );

		$manager = $this->getWikiRequestManager( id: 2 );
		$this->assertFalse( $manager->exists() );
	}

	/**
	 * @covers ::createNewRequestAndLog
	 * @covers ::logNewRequest
	 */
	public function testCreateNewRequestAndLog(): void {
		$manager = $this->getServiceContainer()->getService( 'WikiRequestManager' );
		'@phan-var WikiRequestManager $manager';
		$manager->createNewRequestAndLog(
			data: [
				'subdomain' => 'test2',
				'sitename' => 'Test Wiki 2',
				'language' => 'en',
				'private' => 0,
				'category' => 'uncategorised',
				'bio' => 0,
				'purpose' => 'Test purpose',
				'reason' => 'Test reason',
			],
			extraData: [],
			user: self::$user
		);

		$manager = $this->getWikiRequestManager( id: 2 );
		$this->assertTrue( $manager->exists() );
	}

	/**
	 * @covers ::isDuplicateRequest
	 */
	public function testIsDuplicateRequest(): void {
		$manager = $this->getWikiRequestManager( id: 1 );

		$this->assertTrue( $manager->isDuplicateRequest( 'Test Wiki' ) );
		$this->assertFalse( $manager->isDuplicateRequest( 'New Wiki' ) );
	}

	/**
	 * @covers ::addComment
	 * @covers ::getComments
	 * @covers ::log
	 * @covers ::sendNotification
	 */
	public function testComments(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertArrayEquals( [], $manager->getComments() );

		$manager->addComment(
			comment: 'Test',
			user: $this->getTestUser()->getUser(),
			log: true,
			type: 'comment',
			// Use all involved users
			notifyUsers: []
		);

		$this->assertCount( 1, $manager->getComments() );
	}

	/**
	 * @covers ::addRequestHistory
	 * @covers ::getRequestHistory
	 */
	public function testRequestHistory(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertArrayEquals( [], $manager->getRequestHistory() );

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
	public function testGetID(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame( 1, $manager->getID() );
	}

	/**
	 * @covers ::getDBname
	 */
	public function testGetDBname(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame( 'testwikidb', $manager->getDBname() );
	}

	/**
	 * @covers ::getVisibility
	 */
	public function testGetVisibility(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame(
			WikiRequestManager::VISIBILITY_PUBLIC,
			$manager->getVisibility()
		);
	}

	/**
	 * @covers ::getRequester
	 */
	public function testGetRequester(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame( self::$user->getId(), $manager->getRequester()->getId() );
	}

	/**
	 * @covers ::getStatus
	 */
	public function testGetStatus(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame( 'inreview', $manager->getStatus() );
	}

	/**
	 * @covers ::getSitename
	 */
	public function testGetSitename(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame( 'Test Wiki', $manager->getSitename() );
	}

	/**
	 * @covers ::getLanguage
	 */
	public function testGetLanguage(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame( 'en', $manager->getLanguage() );
	}

	/**
	 * @covers ::getTimestamp
	 */
	public function testGetTimestamp(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame( '20250303234810', $manager->getTimestamp() );
	}

	/**
	 * @covers ::getUrl
	 */
	public function testGetUrl(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame( 'test.example.org', $manager->getUrl() );
	}

	/**
	 * @covers ::getCategory
	 */
	public function testGetCategory(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertSame( 'uncategorised', $manager->getCategory() );
	}

	/**
	 * @covers ::isPrivate
	 */
	public function testIsPrivate(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertFalse( $manager->isPrivate() );
	}

	/**
	 * @covers ::isBio
	 */
	public function testIsBio(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertFalse( $manager->isBio() );
	}

	/**
	 * @covers ::isLocked
	 */
	public function testIsLocked(): void {
		$manager = $this->getWikiRequestManager( id: 1 );
		$this->assertFalse( $manager->isLocked() );
	}
}
