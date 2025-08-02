<?php

namespace Miraheze\CreateWiki\Exceptions;

class MissingWikiError extends ErrorBase {

	public function __construct( string $dbname ) {
		parent::__construct( 'createwiki-error-missingwiki', [ $dbname ] );
	}
}
