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
		ConfigNames::RequestWikiMinimumLength,
		ConfigNames::Subdomain,
		MainConfigNames::LocalDatabases,
	];

	public function __construct(
		private readonly MessageLocalizer $messageLocalizer,
		private readonly ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function checkDatabaseName(
		string $dbname,
		bool $forRename
	): ?string {
		$suffix = $this->options->get( ConfigNames::DatabaseSuffix );
		$suffixed = substr( $dbname, -strlen( $suffix ) ) === $suffix;
		if ( !$suffixed ) {
			return $this->messageLocalizer->msg(
				'createwiki-error-notsuffixed', $suffix
			)->parse();
		}

		if ( !$forRename && $this->databaseExists( $dbname ) ) {
			return $this->messageLocalizer->msg( 'createwiki-error-dbexists' )->parse();
		}

		if ( !ctype_alnum( $dbname ) ) {
			return $this->messageLocalizer->msg( 'createwiki-error-notalnum' )->parse();
		}

		if ( strtolower( $dbname ) !== $dbname ) {
			return $this->messageLocalizer->msg( 'createwiki-error-notlowercase' )->parse();
		}

		return null;
	}

	public function isValidDatabase( ?string $dbname ): bool|string|Message {
		if ( !$dbname || ctype_space( $dbname ) ) {
			return $this->messageLocalizer->msg( 'htmlform-required' );
		}

		$check = $this->checkDatabaseName( $dbname, forRename: false );

		if ( $check ) {
			// Will return a string — the error it received
			return $check;
		}

		return true;
	}

	public function isValidReason( ?string $reason, array $alldata ): bool|Message {
		if ( !isset( $alldata['submit-edit'] ) && isset( $alldata['edit-reason'] ) ) {
			// If we aren't submitting an edit we don't want this to fail.
			return true;
		}

		if ( !$reason || ctype_space( $reason ) ) {
			return $this->messageLocalizer->msg( 'htmlform-required' );
		}

		$minLength = $this->options->get( ConfigNames::RequestWikiMinimumLength );
		if ( $minLength && strlen( $reason ) < $minLength ) {
			// This will automatically call ->parse().
			return $this->messageLocalizer->msg( 'requestwiki-error-minlength' )->numParams(
				$minLength, strlen( $reason )
			);
		}

		if ( $this->isDisallowedRegex( $reason ) ) {
			return $this->messageLocalizer->msg( 'requestwiki-error-invalidcomment' );
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

		$subdomain = $this->getValidSubdomain( $subdomain );
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

	public function getValidSubdomain( string $subdomain ): string {
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

	private function isDisallowedRegex( string $text ): bool {
		$regexes = CreateWikiRegexConstraint::regexesFromMessage(
			'CreateWiki-disallowlist', '/', '/i'
		);

		foreach ( $regexes as $regex ) {
			preg_match( '/' . $regex . '/i', $text, $output );

			if ( is_array( $output ) && count( $output ) >= 1 ) {
				return true;
			}
		}

		return false;
	}
}
