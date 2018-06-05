<?php
class CreateWikiLogFormatter extends LogFormatter {
	protected function getMessageParameters() {
		$params = parent::getMessageParameters();
		$subtype = $this->entry->getSubtype();

		if ( $subtype === 'requestwiki' ) {
			$params[6] = str_replace( '#', '', $params[6] );
			$params[6] = Message::rawParam( Linker::linkKnown(
				Title::newFromText( SpecialPage::getTitleFor( 'RequestWikiQueue' ) . '/' . $params[6] ),
				'#' . $params[6]
			) );
			return $params;
		}
	}
}
