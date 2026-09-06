<?php

namespace Miraheze\CreateWiki\Tests\Unit\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Http\MultiHttpClient;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\MainConfigNames;
use MediaWikiUnitTestCase;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\CacheUpdate;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function json_decode;
use function reset;

/**
 * @group CreateWiki
 * @coversDefaultClass \Miraheze\CreateWiki\Services\CacheUpdate
 */
class CacheUpdateTest extends MediaWikiUnitTestCase {

	private const BASE_CONFIG = [
		ConfigNames::CacheUpdateDebugAccessKey => '',
		ConfigNames::CacheUpdateDebugAccessKeyHeader => '',
		ConfigNames::CacheUpdateDebugHeader => 'X-Debug-Server',
		ConfigNames::CacheUpdateDomain => 'central.example.org',
		ConfigNames::CacheUpdateKey => 'secret-key',
		ConfigNames::CacheUpdateRestEnabled => true,
		ConfigNames::CacheUpdateServers => [ 'server1', 'server2' ],
		MainConfigNames::HTTPProxy => false,
		MainConfigNames::RestPath => '/w/rest.php',
	];

	private function newOptions( array $overrides = [] ): ServiceOptions {
		$config = $overrides + self::BASE_CONFIG;
		$options = $this->createMock( ServiceOptions::class );
		$options->method( 'assertRequiredOptions' )->willReturn( null );
		$options->method( 'get' )->willReturnCallback(
			static fn ( string $name ) => $config[$name]
		);

		return $options;
	}

	private function newCacheUpdate(
		array $configOverrides = [],
		?MultiHttpClient $multiClient = null,
		?JobQueueGroupFactory $jobQueueGroupFactory = null,
		?LoggerInterface $logger = null
	): CacheUpdate {
		$httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$httpRequestFactory->method( 'createMultiClient' )
			->willReturn( $multiClient ?? $this->createMock( MultiHttpClient::class ) );

		return new CacheUpdate(
			$httpRequestFactory,
			$jobQueueGroupFactory ?? $this->createMock( JobQueueGroupFactory::class ),
			$logger ?? new NullLogger(),
			$this->newOptions( $configOverrides )
		);
	}

	/**
	 * @covers ::__construct
	 * @covers ::executeNow
	 * @covers ::isExecutionAllowed
	 */
	public function testExecuteNowReturnsTrueWithNoServersConfigured(): void {
		$cacheUpdate = $this->newCacheUpdate( [ ConfigNames::CacheUpdateServers => [] ] );
		$this->assertTrue( $cacheUpdate->executeNow( 'databases', null ) );
	}

	/**
	 * @covers ::executeNow
	 * @covers ::isExecutionAllowed
	 */
	public function testExecuteNowReturnsTrueWhenRestDisabled(): void {
		$cacheUpdate = $this->newCacheUpdate( [ ConfigNames::CacheUpdateRestEnabled => false ] );
		$this->assertTrue( $cacheUpdate->executeNow( 'databases', null ) );
	}

	/**
	 * @covers ::executeNow
	 * @covers ::isExecutionAllowed
	 */
	public function testExecuteNowReturnsTrueWhenKeyMissing(): void {
		$cacheUpdate = $this->newCacheUpdate( [ ConfigNames::CacheUpdateKey => '' ] );
		$this->assertTrue( $cacheUpdate->executeNow( 'databases', null ) );
	}

	/**
	 * @covers ::executeNow
	 * @covers ::isExecutionAllowed
	 */
	public function testExecuteNowReturnsTrueWhenDomainMissing(): void {
		$cacheUpdate = $this->newCacheUpdate( [ ConfigNames::CacheUpdateDomain => '' ] );
		$this->assertTrue( $cacheUpdate->executeNow( 'databases', null ) );
	}

	/**
	 * @covers ::executeNow
	 * @covers ::isExecutionAllowed
	 */
	public function testExecuteNowReturnsTrueWhenDebugHeaderMissing(): void {
		$cacheUpdate = $this->newCacheUpdate( [ ConfigNames::CacheUpdateDebugHeader => '' ] );
		$this->assertTrue( $cacheUpdate->executeNow( 'databases', null ) );
	}

	/**
	 * @covers ::executeNow
	 */
	public function testExecuteNowSuccessOnAllServers(): void {
		$multiClient = $this->createMock( MultiHttpClient::class );
		$multiClient->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ): array {
				$responses = [];
				foreach ( $requests as $key => $request ) {
					$responses[$key] = [ 'response' => [ 'code' => 204 ] ];
				}

				return $responses;
			}
		);

		$cacheUpdate = $this->newCacheUpdate( [], $multiClient );
		$this->assertTrue( $cacheUpdate->executeNow( 'databases', '{"mtime":1}' ) );
	}

	/**
	 * @covers ::executeNow
	 */
	public function testExecuteNowFailsWhenAServerFails(): void {
		$multiClient = $this->createMock( MultiHttpClient::class );
		$multiClient->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ): array {
				$responses = [];
				$first = true;
				foreach ( $requests as $key => $request ) {
					$responses[$key] = [
						'response' => [ 'code' => $first ? 500 : 204 ],
					];
					$first = false;
				}

				return $responses;
			}
		);

		$cacheUpdate = $this->newCacheUpdate( [], $multiClient );
		$this->assertFalse( $cacheUpdate->executeNow( 'databases', null ) );
	}

	/**
	 * @covers ::executeNow
	 */
	public function testExecuteNowIncludesDebugAccessKeyHeaderWhenConfigured(): void {
		$capturedRequests = null;
		$multiClient = $this->createMock( MultiHttpClient::class );
		$multiClient->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ) use ( &$capturedRequests ): array {
				$capturedRequests = $requests;
				$responses = [];
				foreach ( $requests as $key => $request ) {
					$responses[$key] = [ 'response' => [ 'code' => 204 ] ];
				}

				return $responses;
			}
		);

		$cacheUpdate = $this->newCacheUpdate( [
			ConfigNames::CacheUpdateDebugAccessKeyHeader => 'X-Access-Key',
			ConfigNames::CacheUpdateDebugAccessKey => 'debug-secret',
		], $multiClient );

		$cacheUpdate->executeNow( 'databases', null );

		$this->assertNotNull( $capturedRequests );
		foreach ( $capturedRequests as $request ) {
			$this->assertSame( 'debug-secret', $request['headers']['X-Access-Key'] );
		}
	}

	/**
	 * @covers ::executeNow
	 */
	public function testExecuteNowOmitsDataFromPayloadWhenNull(): void {
		$capturedBody = null;
		$multiClient = $this->createMock( MultiHttpClient::class );
		$multiClient->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ) use ( &$capturedBody ): array {
				$capturedBody = reset( $requests )['body'];
				$responses = [];
				foreach ( $requests as $key => $request ) {
					$responses[$key] = [ 'response' => [ 'code' => 204 ] ];
				}

				return $responses;
			}
		);

		$cacheUpdate = $this->newCacheUpdate( [], $multiClient );
		$cacheUpdate->executeNow( 'databases', null );

		$decoded = json_decode( $capturedBody, true );
		$this->assertArrayNotHasKey( 'data', $decoded );
		$this->assertSame( 'databases', $decoded['name'] );
	}

	/**
	 * @covers ::queueJob
	 */
	public function testQueueJobPushesJobWhenAllowed(): void {
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )->method( 'push' );

		$jobQueueGroupFactory = $this->createMock( JobQueueGroupFactory::class );
		$jobQueueGroupFactory->method( 'makeJobQueueGroup' )->willReturn( $jobQueueGroup );

		$cacheUpdate = $this->newCacheUpdate( [], null, $jobQueueGroupFactory );
		$cacheUpdate->queueJob( 'databases', '{"mtime":1}' );
	}

	/**
	 * @covers ::queueJob
	 * @covers ::isExecutionAllowed
	 */
	public function testQueueJobDoesNothingWhenNotAllowed(): void {
		$jobQueueGroupFactory = $this->createMock( JobQueueGroupFactory::class );
		$jobQueueGroupFactory->expects( $this->never() )->method( 'makeJobQueueGroup' );

		$cacheUpdate = $this->newCacheUpdate(
			[ ConfigNames::CacheUpdateServers => [] ],
			null,
			$jobQueueGroupFactory
		);
		$cacheUpdate->queueJob( 'databases', null );
	}
}
