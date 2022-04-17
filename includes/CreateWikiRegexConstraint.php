<?php

namespace Miraheze\CreateWiki;

use MediaWiki\Logger\LoggerFactory;
use StringUtils;

class CreateWikiRegexConstraint {
	/**
	 * @param string $regex invalid regex to log for
	 * @param string $name name of regex caller (config or message key) to log for
	 */
	private static function warnInvalidRegex( $regex, $name ) {
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
	 * @return void
	 */
	private static function filterInvalidRegexes( &$regexes, $name = '', $start = '', $end = '' ) {
		$regexes = array_filter( $regexes, static function ( $regex ) use ( $name, $start, $end ) {
			if ( !StringUtils::isValidPCRERegex( $start . $regex . $end ) ) {
				if ( $name ) {
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
	 * @return array
	 */
	private static function cleanLines( $lines ) {
		return array_filter(
			array_map( 'trim',
				preg_replace( '/#.*$/', '', $lines )
			)
		);
	}

	/**
	 * @param string $text
	 * @param string $start
	 * @param string $end
	 * @param string $name name of regex caller (config or message key) for logging
	 * @return array
	 */
	private static function regexesFromText( $text, $start = '', $end = '', $name = '' ) {
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
	 * @return string
	 */
	public static function regexFromArray( $regexes, $start, $end, $name = '' ) {
		if ( empty( $regexes ) ) {
			return '';
		}

		self::filterInvalidRegexes( $regexes, $name, $start, $end );

		if ( !empty( $regexes ) ) {
			$regex = $start . implode( '|', $regexes ) . $end;

			if ( StringUtils::isValidPCRERegex( $regex ) ) {
				return $regex;
			}

			if ( $name ) {
				self::warnInvalidRegex( $regex, $name );
			}
		}

		return '';
	}

	/**
	 * @param array|string $regex
	 * @param string $start
	 * @param string $end
	 * @param string $name name of regex caller (config or message key) for logging
	 * @return string
	 */
	public static function regexFromArrayOrString( $regex, $start = '', $end = '', $name = '' ) {
		if ( is_array( $regex ) ) {
			return self::regexFromArray( $regex, $start, $end, $name );
		} else {
			if ( StringUtils::isValidPCRERegex( $regex ) ) {
				return $regex;
			}

			if ( $name ) {
				self::warnInvalidRegex( $regex, $name );
			}
		}

		return '';
	}

	/**
	 * @param string $key
	 * @param string $start prepend to the beginning of each regex line; used only for validation
	 * @param string $end append to the end of each regex line; used only for validation
	 * @return array
	 */
	public static function regexesFromMessage( $key, $start = '/', $end = '/i' ) {
		$message = wfMessage( $key )->inContentLanguage();

		if ( !$message->isDisabled() ) {
			return self::regexesFromText( $message->plain(), $start, $end, "MediaWiki:{$key}" );
		}

		return [];
	}
}
