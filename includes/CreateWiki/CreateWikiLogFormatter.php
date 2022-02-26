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

			$params[6] = Message::rawParam( $linkRenderer->makeKnownLink(
				Title::newFromText( SpecialPage::getTitleFor( 'RequestWikiQueue' ) . '/' . $params[6] ),
				'#' . $params[6]
			) );
		}

		return $params;
	}
}

/**
 * @deprecated since 1.37
 */
class_alias( CreateWikiLogFormatter::class, 'CreateWikiLogFormatter' );
