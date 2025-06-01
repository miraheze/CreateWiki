<?php

namespace Miraheze\CreateWiki\LogFormatter;

use LogEntry;
use LogFormatter;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use function str_replace;

class FarmerLogFormatter extends LogFormatter {

	public function __construct(
		LogEntry $entry,
		private readonly LinkRenderer $linkRenderer
	) {
		parent::__construct( $entry );
	}

	/** @inheritDoc */
	protected function getMessageParameters(): array {
		$params = parent::getMessageParameters();
		$subtype = $this->entry->getSubtype();

		if ( $subtype === 'requestwiki' ) {
			$params[6] = str_replace( '#', '', $params[6] );

			$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestWikiQueue', $params[6] );
			$requestLink = $this->linkRenderer->makeLink( $requestQueueLink, "#{$params[6]}" );

			$params[6] = Message::rawParam( $requestLink );
		}

		return $params;
	}
}
