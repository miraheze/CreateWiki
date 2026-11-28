<?php

namespace Miraheze\CreateWiki\Rest;

use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Miraheze\CreateWiki\Services\CreateWikiDataStore;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;
use function is_array;
use function json_decode;

/**
 * Regenerates the database list cache on the server that receives the
 * request. If a pre-built payload is supplied, it's written as is, no
 * database access at all, otherwise the database is queried directly.
 * POST /createwiki/v0/cache/reset-database-lists
 */
class ResetDatabaseListsHandler extends SimpleHandler {

	public function __construct(
		private readonly CacheRestUtils $restUtils,
		private readonly CreateWikiDataStore $dataStore,
	) {
	}

	public function run(): Response {
		if ( !$this->restUtils->isRestEnabled() ) {
			return $this->getResponseFactory()->createLocalizedHttpError(
				404, new MessageValue( 'createwiki-cacherest-disabled' )
			);
		}

		$clientIp = $this->getRequest()->getServerParams()['REMOTE_ADDR'] ?? '';
		if ( $this->restUtils->isThrottled( $clientIp ) ) {
			return $this->getResponseFactory()->createLocalizedHttpError(
				429, new MessageValue( 'createwiki-cacherest-throttled' )
			);
		}

		$validatedBody = $this->getValidatedBody();

		$key = '';
		$name = 'databases';
		$data = null;
		if ( $validatedBody ) {
			$key = $validatedBody['key'];
			$name = $validatedBody['name'];
			$data = $validatedBody['data'] ?? null;
		}

		if ( !$this->restUtils->isValidKey( $key ) ) {
			$this->restUtils->recordFailure( $clientIp );
			return $this->getResponseFactory()->createLocalizedHttpError(
				403, new MessageValue( 'createwiki-cacherest-invalidkey' )
			);
		}

		if ( $data !== null ) {
			$list = json_decode( $data, true );
			if ( is_array( $list ) ) {
				$this->dataStore->applyDatabaseList( $name, $list );
				return $this->getResponseFactory()->createNoContent();
			}
		}

		// No usable payload was supplied, fall back to querying the
		// database directly rather than doing nothing.
		$this->dataStore->resetDatabaseLists( isNewChanges: false, sync: false );
		return $this->getResponseFactory()->createNoContent();
	}

	public function needsWriteAccess(): true {
		return true;
	}

	/** @inheritDoc */
	public function getBodyParamSettings(): array {
		return [
			'key' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'name' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'data' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
		];
	}
}
