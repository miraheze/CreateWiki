<?php

declare( strict_types = 1 );

namespace Miraheze\CreateWiki\Exceptions;

class MissingWikiError extends ErrorBase {

	public function __construct( string $msg, array $params ) {
		parent::__construct( $msg, $params );
	}
}
