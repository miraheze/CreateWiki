<?php

namespace Miraheze\CreateWiki\CreateWiki;

use LogFormatter;
use MediaWiki\MediaWikiServices;
use Message;
use SpecialPage;
use Title;

class CreateWikiLogFormatter extends LogFormatter {

	/**
	 * @return array
	 */
	protected function getMessageParameters() {
		$params = parent::getMessageParameters();
		$subtype = $this->entry->getSubtype();

		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

		if ( $subtype === 'requestwiki' ) {
			$params[6] = str_replace( '#', '', $params[6] );

			if ( !$this->plaintext ) {
				// @phan-suppress-next-line SecurityCheck-DoubleEscaped
				$params[6] = Message::rawParam( $linkRenderer->makeKnownLink(
					Title::newFromText( SpecialPage::getTitleFor( 'RequestWikiQueue' ) . '/' . $params[6] ),
					'#' . $params[6]
				) );
			} else {
				$params[6] = Message::rawParam(
					Title::newFromText( SpecialPage::getTitleFor( 'RequestWikiQueue' ) . '/' . $params[6] )->getPrefixedText()
				);
			}
		} else {
			$params[3] = str_replace( '#', '', $params[3] );

			if ( !$this->plaintext ) {
				// @phan-suppress-next-line SecurityCheck-DoubleEscaped
				$params[3] = Message::rawParam( $linkRenderer->makeKnownLink(
					Title::newFromText( SpecialPage::getTitleFor( 'RequestWikiQueue' ) . '/' . $params[3] ),
					'#' . $params[3]
				) );
			} else {
				$params[3] = Message::rawParam(
					Title::newFromText( SpecialPage::getTitleFor( 'RequestWikiQueue' ) . '/' . $params[3] )->getPrefixedText()
				v );
			}
		}

		return $params;
	}
}
