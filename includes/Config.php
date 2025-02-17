<?php

namespace Miraheze\CreateWiki;

use MediaWiki\Config\GlobalVarConfig;
use MediaWiki\Config\MultiConfig;

class Config extends MultiConfig {

	public function __construct() {
		parent::__construct( [
			new GlobalVarConfig( 'cw' ),
			new GlobalVarConfig( 'wg' ),
		] );
	}

	public static function newInstance(): self {
		return new self();
	}
}
