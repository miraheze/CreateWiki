<?php

namespace Miraheze\CreateWiki\Exceptions;

use ErrorPageError;
use MediaWiki\Html\Html;
use MediaWiki\Language\RawMessage;
use RuntimeException;

class MissingWikiError extends ErrorPageError {

	public function __construct( string $msg, array $params ) {
		if ( !self::isCommandLine() ) {
			throw new RuntimeException(
				wfMessage( $msg, $params )->inContentLanguage()->escaped()
			);
		}

		$errorBody = new RawMessage( Html::errorBox( wfMessage( $msg, $params )->parse() ) );
		parent::__construct( 'errorpagetitle', $errorBody, [] );
	}
}
