<?php

namespace Miraheze\CreateWiki\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\MainConfigNames;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Jobs\CacheUpdateJob;
use Psr\Log\LoggerInterface;
use function count;
use function implode;
use function json_encode;

class CacheUpdate {

	public const array CONSTRUCTOR_OPTIONS = [
		ConfigNames::CacheUpdateDebugAccessKey,
		ConfigNames::CacheUpdateDebugAccessKeyHeader,
		ConfigNames::CacheUpdateDebugHeader,
		ConfigNames::CacheUpdateDomain,
		ConfigNames::CacheUpdateKey,
		ConfigNames::CacheUpdateRestEnabled,
		ConfigNames::CacheUpdateServers,
		MainConfigNames::HTTPProxy,
		MainConfigNames::RestPath,
	];

	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly LoggerInterface $logger,
		private readonly ServiceOptions $options,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function queueJob( string $name, ?string $data = null ): void {
		if ( !$this->isExecutionAllowed() ) {
			return;
		}

		$params = [ 'name' => $name ];
		if ( $data !== null ) {
			$params['data'] = $data;
		}

		$this->jobQueueGroupFactory->makeJobQueueGroup()->push(
			new JobSpecification( CacheUpdateJob::JOB_NAME, $params )
		);
	}

	public function executeNow( string $name, ?string $data = null ): bool {
		if ( !$this->isExecutionAllowed() ) {
			return true;
		}

		$servers = $this->options->get( ConfigNames::CacheUpdateServers );
		$key = (string)$this->options->get( ConfigNames::CacheUpdateKey );
		$domain = (string)$this->options->get( ConfigNames::CacheUpdateDomain );
		$debugHeader = (string)$this->options->get( ConfigNames::CacheUpdateDebugHeader );

		$restPath = $this->options->get( MainConfigNames::RestPath );
		$url = "https://$domain$restPath/createwiki/v0/cache/reset-database-lists";

		$payload = [ 'key' => $key, 'name' => $name ];
		if ( $data !== null ) {
			$payload['data'] = $data;
		}

		$body = json_encode( $payload );
		$headers = [ 'Content-Type' => 'application/json' ];

		$debugAccessKeyHeader = (string)$this->options->get( ConfigNames::CacheUpdateDebugAccessKeyHeader );
		$debugAccessKey = (string)$this->options->get( ConfigNames::CacheUpdateDebugAccessKey );
		if ( $debugAccessKeyHeader !== '' && $debugAccessKey !== '' ) {
			$headers[$debugAccessKeyHeader] = $debugAccessKey;
		}

		$requests = [];
		foreach ( $servers as $server ) {
			$requests[$server] = [
				'method' => 'POST',
				'url' => $url,
				'body' => $body,
				'headers' => $headers + [
					$debugHeader => $server,
				],
			];
		}

		$http = $this->httpRequestFactory->createMultiClient( [
			'maxConnsPerHost' => 8,
			'usePipelining' => true,
			'proxy' => $this->options->get( MainConfigNames::HTTPProxy ),
		] );

		$responses = $http->runMulti( $requests );

		$failed = [];
		foreach ( $responses as $server => $requestResult ) {
			$code = $requestResult['response']['code'] ?? 0;
			if ( $code !== 204 ) {
				$failed[] = $server;
			}
		}

		if ( $failed !== [] ) {
			$this->logger->error(
				'{class} failed on {count} server(s) for {name}: {servers}',
				[
					'class' => self::class,
					'count' => count( $failed ),
					'name' => $name,
					'servers' => implode( ', ', $failed ),
				]
			);

			return false;
		}

		$this->logger->info( '{class} successful on all servers for {name}.', [ 'class' => self::class, 'name' => $name ] );
		return true;
	}

	private function isExecutionAllowed(): bool {
		$servers = $this->options->get( ConfigNames::CacheUpdateServers );
		if ( $servers === [] ) {
			// No servers configured.
			return false;
		}

		if ( !$this->options->get( ConfigNames::CacheUpdateRestEnabled ) ) {
			$this->logger->error(
				'{class} can not run, {config} is disabled.',
				[
					'class' => self::class,
					'config' => ConfigNames::CacheUpdateRestEnabled,
				]
			);

			return false;
		}

		$key = (string)$this->options->get( ConfigNames::CacheUpdateKey );
		$domain = (string)$this->options->get( ConfigNames::CacheUpdateDomain );
		$debugHeader = (string)$this->options->get( ConfigNames::CacheUpdateDebugHeader );

		if ( $key === '' || $domain === '' || $debugHeader === '' ) {
			$this->logger->error(
				'{class} can not run, one of {keys} is not configured.',
				[
					'class' => self::class,
					'keys' => implode( ', ', [
						ConfigNames::CacheUpdateDebugHeader,
						ConfigNames::CacheUpdateDomain,
						ConfigNames::CacheUpdateKey,
					] ),
				]
			);

			return false;
		}

		return true;
	}
}
