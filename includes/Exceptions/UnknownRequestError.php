<?php

declare( strict_types = 1 );

namespace Miraheze\CreateWiki\Exceptions;

class UnknownRequestError extends ErrorBase {

	public function __construct() {
		parent::__construct( 'requestwiki-unknown', [] );
	}
}
