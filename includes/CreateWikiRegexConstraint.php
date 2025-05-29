<?php

namespace Miraheze\CreateWiki;

use MediaWiki\Logger\LoggerFactory;
use StringUtils;
use function array_filter;
use function array_map;
use function explode;
use function implode;
use function preg_replace;
use function wfMessage;

class CreateWikiRegexConstraint {

	/**
	 * @param string $regex invalid regex to log for
	 * @param string $name name of regex caller (config or message key) to log for
	 */
	private static function warnInvalidRegex(
		string $regex,
		string $name
	): void {
		LoggerFactory::getInstance( 'CreateWiki' )->warning(
			'{name} contains invalid regex',
			[
				'name' => $name,
				'regex' => $regex,
			]
		);
	}

	/**
	 * @param array &$regexes
	 * @param string $name name of regex caller (config or message key) for logging
	 * @param string $start
	 * @param string $end
	 */
	private static function filterInvalidRegexes(
		array &$regexes,
		string $name,
		string $start,
		string $end
	): void {
		$regexes = array_filter( $regexes, static function ( $regex ) use ( $name, $start, $end ) {
			if ( !StringUtils::isValidPCRERegex( $start . $regex . $end ) ) {
				if ( $name !== '' ) {
					self::warnInvalidRegex( $regex, $name );
				}

				return false;
			}

			return true;
		} );
	}

	/**
	 * Strip comments and whitespace, and remove blank lines
	 *
	 * @param array $lines
	 * @return array Cleaned lines
	 */
	private static function cleanLines( array $lines ): array {
		return array_filter(
			array_map( 'trim',
				preg_replace( '/^\s*#.*$/', '', $lines )
			)
		);
	}

	/**
	 * @param string $text
	 * @param string $start
	 * @param string $end
	 * @param string $name name of regex caller (config or message key) for logging
	 * @return array Array of regexes with invalid ones filtered out.
	 */
	private static function regexesFromText(
		string $text,
		string $start,
		string $end,
		string $name
	): array {
		$lines = explode( "\n", $text );
		$regexes = self::cleanLines( $lines );

		self::filterInvalidRegexes( $regexes, $name, $start, $end );
		return $regexes;
	}

	/**
	 * @param array $regexes array of regexes to use for making into a string
	 * @param string $start prepend to the beginning of the regex
	 * @param string $end append to the end of the regex
	 * @param string $name name of regex caller (config or message key) for logging
	 * @return string Valid regex
	 */
	public static function regexFromArray(
		array $regexes,
		string $start,
		string $end,
		string $name
	): string {
		if ( !$regexes ) {
			return '';
		}

		self::filterInvalidRegexes( $regexes, $name, $start, $end );

		if ( $regexes ) {
			$regex = $start . implode( '|', $regexes ) . $end;

			if ( StringUtils::isValidPCRERegex( $regex ) ) {
				return $regex;
			}

			if ( $name !== '' ) {
				self::warnInvalidRegex( $regex, $name );
			}
		}

		return '';
	}

	/**
	 * @param string $key
	 * @param string $start prepend to the beginning of each regex line; used only for validation
	 * @param string $end append to the end of each regex line; used only for validation
	 * @return array Array of regexes with invalid ones filtered out.
	 */
	public static function regexesFromMessage(
		string $key,
		string $start,
		string $end
	): array {
		$message = wfMessage( $key )->inContentLanguage();

		if ( !$message->isDisabled() ) {
			return self::regexesFromText( $message->plain(), $start, $end, "MediaWiki:{$key}" );
		}

		return [];
	}
}
