<?php

namespace Miraheze\CreateWiki\Exceptions;

use ErrorPageError;
use MediaWiki\Html\Html;
use MediaWiki\Language\RawMessage;

class MissingWikiError extends ErrorPageError {
	public function __construct( string $msg, array $params ) {
		$errorBody = new RawMessage( Html::errorBox( wfMessage( $msg, $params )->parse() ) );
		parent::__construct( 'errorpagetitle', $errorBody, [] );
	}
}
