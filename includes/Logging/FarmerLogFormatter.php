<?php

namespace Miraheze\CreateWiki\Logging;

use MediaWiki\Logging\LogFormatter;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use Wikimedia\Message\ScalarParam;

class FarmerLogFormatter extends LogFormatter {

	public const string TYPE_REQUEST_LINK = 'request-link';

	/** @inheritDoc */
	protected function formatParameterValue( mixed $type, mixed $value ): mixed {
		return match ( $type ) {
			self::TYPE_REQUEST_LINK => $this->formatRequestLink( (int)$value ),
			default => parent::formatParameterValue( $type, $value ),
		};
	}

	private function formatRequestLink( int $requestId ): ScalarParam|string {
		$title = SpecialPage::getTitleFor( 'RequestWikiQueue', (string)$requestId );
		$link = $this->makePageLink( $title, [], "#$requestId" );

		return $this->plaintext ? $link : Message::rawParam( $link );
	}
}
