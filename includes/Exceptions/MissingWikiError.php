<?php

declare( strict_types = 1 );

namespace Miraheze\CreateWiki\Exceptions;

class MissingWikiError extends ErrorBase {

	public function __construct( string $wiki ) {
		parent::__construct( 'createwiki-error-missingwiki', [ $wiki ] );
	}
}
