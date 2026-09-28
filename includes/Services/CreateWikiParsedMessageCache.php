<?php

namespace Miraheze\CreateWiki\Services;

use MediaWiki\Message\Message;
use Wikimedia\ObjectCache\WANObjectCache;
use function md5;

class CreateWikiParsedMessageCache {

	public function __construct(
		private readonly WANObjectCache $cache,
	) {
	}

	public function parseAsBlock( Message $message ): string {
		if ( !$message->exists() ) {
			return $message->parseAsBlock();
		}

		$key = $this->cache->makeKey(
			'createwiki-parsed-message',
			$message->getKey(),
			$message->getLanguageCode(),
			md5( $message->plain() )
		);

		return $this->cache->getWithSetCallback(
			$key,
			WANObjectCache::TTL_DAY,
			static fn (): string => $message->parseAsBlock()
		);
	}
}
