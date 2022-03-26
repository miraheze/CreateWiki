<?php

namespace Miraheze\CreateWiki;

use StringUtils;

class CreateWikiRegexConstraint {

	/**
	 * @param array $regexes
	 * @return bool
	 */
	private static function validateRegexes( $regexes ) {
		foreach ( $regexes as $regex ) {
			if ( !StringUtils::isValidPCRERegex( $regex ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array &$regexes
	 */
	private static function filterInvalidRegexes( &$regexes ) {
		foreach ( $regexes as $key => $regex ) {
			if ( !StringUtils::isValidPCRERegex( $regex ) ) {
				wfWarn( 'Contains invalid regex.' );

				unset( $regexes[$key] );
			}
		}
	}

	/**
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
	 * @return array
	 */
	private static function regexesFromText( $text ) {
		$lines = explode( "\n", $text );
		$regexes = self::cleanLines( $lines );

		if ( !self::validateRegexes( $regexes ) ) {
			wfWarn( 'Contains invalid regex.' );
		}

		return $regexes;
	}

	/**
	 * @param array $regexes
	 * @param string $start
	 * @param string $end
	 * @return string
	 */
	public static function regexFromArray( $regexes, $start, $end ) {
		if ( empty( $regexes ) ) {
			return '';
		}

		self::filterInvalidRegexes( $regexes );

		if ( !empty( $regexes ) ) {
			$regex = $start . implode( '|', $regexes ) . $end;

			if ( self::validateRegexes( [ $regex ] ) ) {
				return $regex;
			}
		}

		wfWarn( 'Contains invalid regex.' );

		return '';
	}

	/**
	 * @param array|string $regex
	 * @param string $start
	 * @param string $end
	 * @return string
	 */
	public static function regexFromArrayOrString( $regex, $start = '', $end = '' ) {
		if ( is_array( $regex ) ) {
			return self::regexFromArray( $regex, $start, $end );
		} else {
			if ( self::validateRegexes( [ $regex ] ) ) {
				return $regex;
			}
		}

		wfWarn( 'Contains invalid regex.' );

		return '';
	}

	/**
	 * @param string $key
	 * @return array
	 */
	public static function regexesFromMessage( $key ) {
		$message = wfMessage( $key )->inContentLanguage();

		if ( !$message->isDisabled() ) {
			return self::regexesFromText( $message->plain() );
		}

		return [];
	}
}
