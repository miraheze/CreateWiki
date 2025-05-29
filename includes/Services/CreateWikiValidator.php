<?php

namespace Miraheze\CreateWiki\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MessageLocalizer;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use function ctype_alnum;
use function ctype_space;
use function in_array;
use function preg_match;
use function str_ends_with;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;

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
			if ( preg_match( '/' . $regex . '/i', $text ) ) {
				return true;
			}
		}

		return false;
	}

	public function databaseExists( string $database ): bool {
		return in_array( $database, $this->options->get( MainConfigNames::LocalDatabases ), true );
	}

	public function getValidSubdomain( string $subdomain ): string {
		$subdomain = strtolower( $subdomain );
		$configSubdomain = $this->options->get( ConfigNames::Subdomain );

		if ( str_ends_with( $subdomain, '.' . $configSubdomain ) ) {
			$subdomain = str_replace( '.' . $configSubdomain, '', $subdomain );
		}

		return $subdomain;
	}

	public function getValidUrl( string $dbname ): string {
		$domain = $this->options->get( ConfigNames::Subdomain );
		$subdomain = substr(
			$dbname, 0,
			-strlen( $this->options->get( ConfigNames::DatabaseSuffix ) )
		);

		return "https://$subdomain.$domain";
	}

	public function validateAgreement( bool $agreement ): bool|Message {
		if ( !$agreement ) {
			return $this->messageLocalizer->msg( 'createwiki-error-agreement' );
		}

		return true;
	}

	public function validateComment( ?string $comment, array $alldata ): bool|Message {
		if ( isset( $alldata['submit-comment'] ) && ( !$comment || ctype_space( $comment ) ) ) {
			return $this->messageLocalizer->msg( 'htmlform-required' );
		}

		return true;
	}

	public function validateDatabaseEntry( ?string $dbname ): bool|string|Message {
		if ( !$dbname || ctype_space( $dbname ) ) {
			return $this->messageLocalizer->msg( 'htmlform-required' );
		}

		$check = $this->validateDatabaseName( $dbname, $this->databaseExists( $dbname ) );

		if ( $check ) {
			// Will return a string — the error it received
			return $check;
		}

		return true;
	}

	public function validateDatabaseName(
		string $dbname,
		bool $exists
	): ?string {
		$suffix = $this->options->get( ConfigNames::DatabaseSuffix );
		$suffixed = str_ends_with( $dbname, $suffix );
		if ( !$suffixed ) {
			return $this->messageLocalizer->msg(
				'createwiki-error-notsuffixed', $suffix
			)->parse();
		}

		if ( $exists ) {
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

	public function validateReason( ?string $reason, array $alldata ): bool|Message {
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

	public function validateStatusComment( ?string $comment, array $alldata ): bool|Message {
		if ( isset( $alldata['submit-handle'] ) && ( !$comment || ctype_space( $comment ) ) ) {
			return $this->messageLocalizer->msg( 'htmlform-required' );
		}

		return true;
	}

	public function validateSubdomain( ?string $subdomain, array $alldata ): bool|Message {
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
}
