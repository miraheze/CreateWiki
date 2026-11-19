<?php

namespace Miraheze\CreateWiki\Tests\Maintenance;

use MediaWiki\Logging\LogEntryBase;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\Title\TitleValue;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\Maintenance\MigrateLogParams;
use Wikimedia\TestingAccessWrapper;
use const NS_SPECIAL;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Maintenance\MigrateLogParams
 */
class MigrateLogParamsTest extends MaintenanceBaseTestCase {

	protected function getMaintenanceClass(): string {
		return MigrateLogParams::class;
	}

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( MainConfigNames::VirtualDomainsMapping, [
			'virtual-createwiki-central' => [ 'db' => WikiMap::getCurrentWikiId() ],
		] );
	}

	private function addLog( string $type, string $action, string $title, array $params ): int {
		$entry = new ManualLogEntry( $type, $action );
		$entry->setPerformer( $this->getTestUser()->getUser() );
		$entry->setTarget( new TitleValue( NS_SPECIAL, $title ) );
		$entry->setParameters( $params );

		return $entry->insert( $this->getDb() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getParams( int $logId ): array {
		$blob = $this->getDb()->newSelectQueryBuilder()
			->select( 'log_params' )
			->from( 'logging' )
			->where( [ 'log_id' => $logId ] )
			->caller( __METHOD__ )
			->fetchField();

		// @phan-suppress-next-line SecurityCheck-PHPSerializeInjection Classes are not allowed
		$params = LogEntryBase::extractParams( (string)$blob );
		$this->assertIsArray( $params );
		'@phan-var array<string, mixed> $params';

		return $params;
	}

	/**
	 * @covers ::doDBUpdates
	 * @covers ::convert
	 * @covers ::getRequestId
	 */
	public function testMigratesRequestEntries(): void {
		$approve = $this->addLog( 'farmer', 'requestapprove', 'RequestWikiQueue/7', [
			'4::id' => Message::rawParam( '<a href="/x">#7</a>' ),
		] );

		$suppress = $this->addLog( 'farmersuppression', 'suppress', 'RequestWikiQueue/8', [
			'4::id' => Message::rawParam( '<a href="/x">#8</a>' ),
		] );

		$requested = $this->addLog( 'farmer', 'requestwiki', 'RequestWiki', [
			'4::sitename' => 'Test Wiki',
			'5::language' => 'en',
			'6::private' => 0,
			'7::id' => '#9',
		] );

		$this->expectOutputRegex( '/Migrated 3 log entries\. Skipped 0\./' );
		$this->maintenance->execute();

		$this->assertSame( [ '4:request-link:id' => 7 ], $this->getParams( $approve ) );
		$this->assertSame( [ '4:request-link:id' => 8 ], $this->getParams( $suppress ) );
		$this->assertSame( [
			'4::sitename' => 'Test Wiki',
			'5::language' => 'en',
			'6::private' => 0,
			'7:request-link:id' => 9,
		], $this->getParams( $requested ) );
	}

	/**
	 * @covers ::doDBUpdates
	 * @covers ::getRequestId
	 */
	public function testReadsRequestFromLinkWhenTitleHasNoId(): void {
		$logId = $this->addLog( 'farmer', 'comment', 'RequestWikiQueue', [
			'4::id' => Message::rawParam( '<a href="/x">#12</a>' ),
		] );

		$this->expectOutputRegex( '/Migrated 1 log entries\./' );
		$this->maintenance->execute();

		$this->assertSame( [ '4:request-link:id' => 12 ], $this->getParams( $logId ) );
	}

	/**
	 * @covers ::doDBUpdates
	 */
	public function testSkipsEntriesWithoutARequestId(): void {
		$logId = $this->addLog( 'farmer', 'comment', 'RequestWikiQueue', [
			'4::id' => 'unknown',
		] );

		$this->expectOutputRegex( '/Skipping\.\n.*Migrated 0 log entries\. Skipped 1\./s' );
		$this->maintenance->execute();

		$this->assertSame( [ '4::id' => 'unknown' ], $this->getParams( $logId ) );
	}

	/**
	 * @covers ::doDBUpdates
	 * @covers ::needsMigration
	 */
	public function testIgnoresEntriesThatAreAlreadyMigrated(): void {
		$params = [ '4:request-link:id' => 5 ];
		$logId = $this->addLog( 'farmer', 'comment', 'RequestWikiQueue/5', $params );
		$created = $this->addLog( 'farmer', 'createwiki', 'CreateWiki', [ '4::wiki' => 'testwiki' ] );

		$this->expectOutputRegex( '/Migrated 0 log entries/' );
		$this->maintenance->execute();

		$this->assertSame( $params, $this->getParams( $logId ) );
		$this->assertSame( [ '4::wiki' => 'testwiki' ], $this->getParams( $created ) );
	}

	/**
	 * @covers ::doDBUpdates
	 */
	public function testIgnoresOtherLogTypes(): void {
		$logId = $this->addLog( 'otherlog', 'comment', 'RequestWikiQueue/1', [
			'4::id' => Message::rawParam( '<a href="/x">#1</a>' ),
		] );

		$this->expectOutputRegex( '/Migrated 0 log entries/' );
		$this->maintenance->execute();

		$this->assertArrayHasKey( '4::id', $this->getParams( $logId ) );
	}

	/**
	 * @covers ::doDBUpdates
	 */
	public function testBatches(): void {
		$logIds = [];
		foreach ( [ 1, 2, 3 ] as $requestId ) {
			$logIds[$requestId] = $this->addLog( 'farmer', 'comment', "RequestWikiQueue/$requestId", [
				'4::id' => Message::rawParam( "<a href=\"/x\">#$requestId</a>" ),
			] );
		}

		$maintenance = $this->maintenance;
		'@phan-var TestingAccessWrapper $maintenance';
		$maintenance->setBatchSize( 1 );

		$this->expectOutputRegex( '/Migrated 3 log entries/' );
		$this->maintenance->execute();

		foreach ( $logIds as $requestId => $logId ) {
			$this->assertSame( $requestId, $this->getParams( $logId )['4:request-link:id'] );
		}
	}
}
