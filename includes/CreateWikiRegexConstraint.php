<?php

namespace Miraheze\CreateWiki;

use MediaWiki\Logger\LoggerFactory;
use StringUtils;

class CreateWikiRegexConstraint {
	/**
	 * @param string $regex invalid regex to log for
	 * @param string $name name of regex caller (config or message) to log for
	 */
	private static function logInvalidRegex( $regex, $name ) {
		LoggerFactory::getInstance( 'CreateWiki' )->info(
			'{name} contains invalid regex',
			[
				'name' => $name,
				'regex' => $regex,
			]
		);
	}

	/**
	 * @param array $regexes
	 * @param string $name name of regex caller (config or message) for logging
	 * @return bool
	 */
	private static function validateRegexes( $regexes, $name = '' ) {
		foreach ( $regexes as $regex ) {
			if ( !StringUtils::isValidPCRERegex( $regex ) ) {
				if ( $name ) {
					self::logInvalidRegex( $regex, $name );
				}

				return false;
			}
		}

		return true;
	}

	/**
	 * @param array &$regexes
	 * @param string $name name of regex caller (config or message) for logging
	 */
	private static function filterInvalidRegexes( &$regexes, $name = '' ) {
		foreach ( $regexes as $key => $regex ) {
			if ( !StringUtils::isValidPCRERegex( $regex ) ) {
				if ( $name ) {
					self::logInvalidRegex( $regex, $name );
				}

				unset( $regexes[$key] );
			}
		}
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
	 * @param string $name name of regex caller (config or message) for logging
	 * @return array
	 */
	private static function regexesFromText( $text, $name = '' ) {
		$lines = explode( "\n", $text );
		$regexes = self::cleanLines( $lines );

		self::validateRegexes( $regexes, $name );

		return $regexes;
	}

	/**
	 * @param array $regexes array of regexes to use for making into a string
	 * @param string $start prepend to the beginning of the regex
	 * @param string $end append to the end of the regex
	 * @param string $name name of regex caller (config or message) for logging
	 * @return string
	 */
	public static function regexFromArray( $regexes, $start, $end, $name = '' ) {
		if ( empty( $regexes ) ) {
			return '';
		}

		self::filterInvalidRegexes( $regexes, $name );

		if ( !empty( $regexes ) ) {
			$regex = $start . implode( '|', $regexes ) . $end;

			if ( self::validateRegexes( [ $regex ] ) ) {
				return $regex;
			}

			if ( $name ) {
				self::logInvalidRegex( $regex, $name );
			}
		}

		return '';
	}

	/**
	 * @param array|string $regex
	 * @param string $start
	 * @param string $end
	 * @param string $name name of regex caller (config or message) for logging
	 * @return string
	 */
	public static function regexFromArrayOrString( $regex, $start = '', $end = '', $name = '' ) {
		if ( is_array( $regex ) ) {
			return self::regexFromArray( $regex, $start, $end );
		} else {
			if ( self::validateRegexes( [ $regex ] ) ) {
				return $regex;
			}

			if ( $name ) {
				self::logInvalidRegex( $regex, $name );
			}
		}

		return '';
	}

	/**
	 * @param string $key
	 * @return array
	 */
	public static function regexesFromMessage( $key ) {
		$message = wfMessage( $key )->inContentLanguage();

		if ( !$message->isDisabled() ) {
			return self::regexesFromText( $message->plain(), $key );
		}

		return [];
	}
}
