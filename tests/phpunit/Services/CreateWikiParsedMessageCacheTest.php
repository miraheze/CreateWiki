<?php

namespace Miraheze\CreateWiki\Tests\Services;

use MediaWiki\Message\Message;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\Services\CreateWikiParsedMessageCache;

/**
 * @group CreateWiki
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Services\CreateWikiParsedMessageCache
 */
class CreateWikiParsedMessageCacheTest extends MediaWikiIntegrationTestCase {

	private function newCache(): CreateWikiParsedMessageCache {
		return new CreateWikiParsedMessageCache(
			$this->getServiceContainer()->getMainWANObjectCache()
		);
	}

	/**
	 * @covers ::parseAsBlock
	 */
	public function testParseAsBlockSkipsCacheForNonExistentMessage(): void {
		$message = $this->createMock( Message::class );
		$message->method( 'exists' )->willReturn( false );
		$message->expects( $this->once() )->method( 'parseAsBlock' )->willReturn( 'rendered' );

		$this->assertSame( 'rendered', $this->newCache()->parseAsBlock( $message ) );
	}

	/**
	 * @covers ::parseAsBlock
	 */
	public function testParseAsBlockCachesResultAcrossCalls(): void {
		$message = $this->createMock( Message::class );
		$message->method( 'exists' )->willReturn( true );
		$message->method( 'getKey' )->willReturn( 'createwiki-parsed-message-cache-test' );
		$message->method( 'getLanguageCode' )->willReturn( 'en' );
		$message->method( 'plain' )->willReturn( 'Some wikitext' );
		$message->expects( $this->once() )->method( 'parseAsBlock' )->willReturn( '<p>Some wikitext</p>' );

		$cache = $this->newCache();

		$this->assertSame( '<p>Some wikitext</p>', $cache->parseAsBlock( $message ) );
		// @phan-suppress-next-line PhanPluginDuplicateAdjacentStatement
		$this->assertSame( '<p>Some wikitext</p>', $cache->parseAsBlock( $message ) );
	}

	/**
	 * @covers ::parseAsBlock
	 */
	public function testParseAsBlockUsesDistinctEntriesForDifferentText(): void {
		$cache = $this->newCache();

		$original = $this->createMock( Message::class );
		$original->method( 'exists' )->willReturn( true );
		$original->method( 'getKey' )->willReturn( 'createwiki-parsed-message-cache-test-edit' );
		$original->method( 'getLanguageCode' )->willReturn( 'en' );
		$original->method( 'plain' )->willReturn( 'Original text' );
		$original->method( 'parseAsBlock' )->willReturn( '<p>Original text</p>' );

		$edited = $this->createMock( Message::class );
		$edited->method( 'exists' )->willReturn( true );
		$edited->method( 'getKey' )->willReturn( 'createwiki-parsed-message-cache-test-edit' );
		$edited->method( 'getLanguageCode' )->willReturn( 'en' );
		$edited->method( 'plain' )->willReturn( 'Edited text' );
		$edited->method( 'parseAsBlock' )->willReturn( '<p>Edited text</p>' );

		$this->assertSame( '<p>Original text</p>', $cache->parseAsBlock( $original ) );
		$this->assertSame( '<p>Edited text</p>', $cache->parseAsBlock( $edited ) );
	}

	/**
	 * @covers ::parseAsBlock
	 */
	public function testParseAsBlockUsesDistinctEntriesForDifferentLanguage(): void {
		$cache = $this->newCache();

		$english = $this->createMock( Message::class );
		$english->method( 'exists' )->willReturn( true );
		$english->method( 'getKey' )->willReturn( 'createwiki-parsed-message-cache-test-lang' );
		$english->method( 'getLanguageCode' )->willReturn( 'en' );
		$english->method( 'plain' )->willReturn( 'Same wikitext' );
		$english->method( 'parseAsBlock' )->willReturn( '<p>English</p>' );

		$french = $this->createMock( Message::class );
		$french->method( 'exists' )->willReturn( true );
		$french->method( 'getKey' )->willReturn( 'createwiki-parsed-message-cache-test-lang' );
		$french->method( 'getLanguageCode' )->willReturn( 'fr' );
		$french->method( 'plain' )->willReturn( 'Same wikitext' );
		$french->method( 'parseAsBlock' )->willReturn( '<p>French</p>' );

		$this->assertSame( '<p>English</p>', $cache->parseAsBlock( $english ) );
		$this->assertSame( '<p>French</p>', $cache->parseAsBlock( $french ) );
	}
}
