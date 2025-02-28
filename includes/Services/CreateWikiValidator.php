<?php

namespace Miraheze\CreateWiki\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MessageLocalizer;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;

class CreateWikiValidator {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::DatabaseSuffix,
		ConfigNames::DisallowedSubdomains,
		ConfigNames::Subdomain,
		MainConfigNames::LocalDatabases,
	];

	public function __construct(
		private readonly MessageLocalizer $messageLocalizer,
		private readonly WikiManagerFactory $wikiManagerFactory,
		private readonly ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function isValidDatabase( ?string $dbname ): bool|Message {
		if ( !$dbname || ctype_space( $dbname ) ) {
			return $this->messageLocalizer->msg( 'htmlform-required' );
		}

		$wikiManager = $this->wikiManagerFactory->newInstance( $dbname );
		$check = $wikiManager->checkDatabaseName( $dbname, forRename: false );

		if ( $check ) {
			// Will return a string — the error it received
			return $check;
		}

		return true;
	}

	public function isValidSubdomain( ?string $subdomain, array $alldata ): bool|Message {
		if ( !isset( $alldata['submit-edit'] ) && isset( $alldata['edit-url'] ) ) {
			// If we aren't submitting an edit we don't want this to fail.
			// For example, we don't want an invalid subdomain to block
			// adding a comment or declining the request.
			return true;
		}

		if ( !$subdomain || ctype_space( $subdomain ) ) {
			return $this->messageLocalizer->msg( 'htmlform-required' );
		}

		$subdomain = $this->getFilteredSubdomain( $subdomain );
		$database = $subdomain . $this->options->get( ConfigNames::DatabaseSuffix );

		if ( $this->databaseExists( $database ) ) {
			return $this->messageLocalizer->msg( 'createwiki-error-subdomaintaken' );
		}

		if ( !ctype_alnum( $subdomain ) ) {
			return $this->messageLocalizer->msg( 'createwiki-error-notalnum' );
		}

		if ( preg_match( $this->getDisallowedSubdomains(), $subdomain ) ) {
			return $this->messageLocalizer->msg( 'createwiki-error-disallowed' );
		}

		return true;
	}

	public function getFilteredSubdomain( string $subdomain ): string {
		$subdomain = strtolower( $subdomain );
		$configSubdomain = $this->options->get( ConfigNames::Subdomain );

		if ( strpos( $subdomain, $configSubdomain ) !== false ) {
			$subdomain = str_replace( '.' . $configSubdomain, '', $subdomain );
		}

		return $subdomain;
	}

	private function databaseExists( string $database ): bool {
		return in_array( $database, $this->options->get( MainConfigNames::LocalDatabases ) );
	}

	private function getDisallowedSubdomains(): string {
		return CreateWikiRegexConstraint::regexFromArray(
			$this->options->get( ConfigNames::DisallowedSubdomains ), '/^(', ')+$/',
			ConfigNames::DisallowedSubdomains
		);
	}
}
