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
	 * @param array $lines
	 * @return array
	 */
	private static function regexesFromText( $lines ) {
		$regexes = self::cleanLines( $lines );

		if ( self::validateRegexes( $regexes ) ) {
			return $regexes;
		}

		return [];
	}

	/**
	 * @param string $key
	 * @return array
	 */
	public static function regexesFromMessage( $key ) {
		$message = wfMessage( $key )->inContentLanguage();

		if ( !$message->isDisabled() ) {
			return self::regexesFromText( explode( "\n", $message->plain() ) );
		}

		return [];
	}
}
