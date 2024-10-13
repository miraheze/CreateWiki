<?php

namespace Miraheze\CreateWiki\Exceptions;

class MissingWikiError extends BaseWikiError {

	public function __construct( string $msg, array $params ) {
		parent::__construct( $msg, $params );
	}
}
