<?php

namespace Miraheze\CreateWiki\Exceptions;

class UnknownRequestError extends BaseWikiError {

	public function __construct() {
		parent::__construct( 'requestwiki-unknown', [] );
	}
}
