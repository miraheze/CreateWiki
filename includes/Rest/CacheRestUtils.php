<?php

namespace Miraheze\CreateWiki\Rest;

use MediaWiki\Config\ServiceOptions;
use Miraheze\CreateWiki\ConfigNames;
use Wikimedia\ObjectCache\BagOStuff;
use function hash_equals;

class CacheRestUtils {

	public const array CONSTRUCTOR_OPTIONS = [
		ConfigNames::CacheUpdateKey,
		ConfigNames::CacheUpdateRestEnabled,
	];

	private const int MAX_ATTEMPTS = 10;
	private const int THROTTLE_SECONDS = 60;

	public function __construct(
		private readonly BagOStuff $cache,
		private readonly ServiceOptions $options,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function isRestEnabled(): bool {
		return (bool)$this->options->get( ConfigNames::CacheUpdateRestEnabled );
	}

	public function isValidKey( string $providedKey ): bool {
		$configuredKey = (string)$this->options->get( ConfigNames::CacheUpdateKey );
		return $configuredKey !== '' && hash_equals( $configuredKey, $providedKey );
	}

	public function isThrottled( string $clientIp ): bool {
		return (int)$this->cache->get( $this->throttleKey( $clientIp ) ) >= self::MAX_ATTEMPTS;
	}

	public function recordFailure( string $clientIp ): void {
		$key = $this->throttleKey( $clientIp );
		$attempts = $this->cache->incrWithInit( $key, self::THROTTLE_SECONDS, 1, 1 );
		if ( $attempts === false ) {
			$this->cache->set( $key, 1, self::THROTTLE_SECONDS );
		}
	}

	private function throttleKey( string $clientIp ): string {
		return $this->cache->makeGlobalKey( 'CreateWiki', 'cache-reset-database-lists-attempts', $clientIp );
	}
}
