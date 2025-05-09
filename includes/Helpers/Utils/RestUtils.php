<?php

namespace Miraheze\CreateWiki\Helpers\Utils;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Rest\LocalizedHttpException;
use Miraheze\CreateWiki\ConfigNames;
use Wikimedia\Message\MessageValue;

class RestUtils {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::EnableRESTAPI,
	];

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * Called from the REST handlers.
	 *
	 * Checks that the current wiki is the central wiki and
	 * that the REST API is not disabled.
	 */
	public function checkEnv(): void {
		if ( !$this->databaseUtils->isCurrentWikiCentral() ) {
			throw new LocalizedHttpException( new MessageValue( 'createwiki-wikinotcentralwiki' ), 403 );
		}

		if ( !$this->options->get( ConfigNames::EnableRESTAPI ) ) {
			throw new LocalizedHttpException( new MessageValue( 'createwiki-rest-disabled' ), 403 );
		}
	}
}
