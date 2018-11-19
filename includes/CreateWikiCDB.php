<?php

class CreateWikiCDB {
	public static function latest( string $wiki ) {
		global $wgCreateWikiCDBDirectory;

		if ( $wgCreateWikiCDBDirectory ) {
			// all the cache stuff
			$cache = ObjectCache::getLocalClusterInstance();
			$key = $cache->makeKey( 'CreateWiki', 'version' );
			$cacheVersion = $cache->get( $key );

			// all the CBD stuff
			$cdbrVersion = CreateWikiCDB::get( $wiki, 'version' );

			if ( !(bool)$cacheVersion || !(bool)$cdbrVersion || (int)$cdbrVersion != (int)$cacheVersion ) {
				return false;
			}
		}

		return true;
	}

	public static function get( string $wiki, $section ) {
		global $wgCreateWikiCDBDirectory;

		if ( $wgCreateWikiCDBDirectory ) {
			$cdbfile = "$wgCreateWikiCDBDirectory/$wiki.cdb";

			if ( file_exists( $cdbfile ) ) {
				$cdbr = \Cdb\Reader::open( $cdbfile );

				if ( is_array( $section ) ) {
					$returnArray = [];

					foreach ( $section as $key ) {
						$returnArray[$key] = $cdbr->get( $key );
					}

					return $returnArray;
				}

				return $cdbr->get( $section );
			}
		}

		return null;
	}

	public static function upsert( string $wiki ) {
		// upsert is a mashed term of "update" and "insert"
		// function should update a CDB or ensure one exists.
		global $wgCreateWikiCDBDirectory, $wgCreateWikiDatabase;

		if ( $wgCreateWikiCDBDirectory ) {
			$cdbFile = "$wgCreateWikiCDBDirectory/$wiki.cdb";

			$dbr = wfGetDB( DB_REPLICA, [], $wgCreateWikiDatabase );

			$cwWikis = $dbr->selectRow(
				'cw_wikis',
				[
					'wiki_sitename',
					'wiki_language',
					'wiki_private',
					'wiki_creation',
					'wiki_closed',
					'wiki_closed_timestamp',
					'wiki_inactive',
					'wiki_inactive_timestamp',
					'wiki_settings',
					'wiki_extensions',
					'wiki_category'
				],
				[
					'wiki_dbname' => $wiki
				],
				__METHOD__
			);

			// This will be an array that is pushed to CDB.
			// It will contain everything and is very important!
			$cacheArray = [
				'sitename' => (string)$cwWikis->wiki_sitename,
				'language' => $cwWikis->wiki_language,
				'private' => (bool)$cwWikis->wiki_private,
				'creation' => $cwWikis->wiki_creation,
				'closed' => (bool)$cwWikis->wiki_closed,
				'closedTimestamp' => $cwWikis->wiki_closed_timestamp,
				'inactive' => (bool)$cwWikis->wiki_inactive,
				'inactiveTimestamp' => $cwWikis->wiki_inactive_timestamp,
				'extensions' => $cwWikis->wiki_extensions, // straight write but this WILL NOT work.
				'category' => $cwWikis->wiki_category
			];

			// Let's expand settings out since it's meant to be a large array
			// We can pass it onto the hook as well for more complicated settings
			$settingsArray = json_decode( $cwWikis->wiki_settings, true );

			Hooks::run( 'CreateWikiCDBUpserting', [ $dbr, $wiki, &$settingsArray ] );

			// Let's add our settingsArray to cacheArray now Hooks are over
			$cacheArray['settings'] = json_encode( $settingsArray );

			// Let's grab the cache version
			$cache = ObjectCache::getLocalClusterInstance();
			$key = $cache->makeKey( 'CreateWiki', 'version' );
			$cacheVersion = $cache->get( $key );

			// CDB version
			if ( file_exists( $cdbFile ) ) {
				$cdbr = \Cdb\Reader::open( $cdbFile );
			}

			$cdbVersion = ( isset( $cdbr ) ) ? $cdbr->get( 'version' ) : null;

			// If we're running an out of date version, let's do nothing.
			// If we're ruinning "the latest", let's increase it.
			// If we don't have a key... let's make one!
			if ( $cacheVersion == $cdbVersion) {
				$cacheVersion = (int)$cache->incr( $key );
			} elseif ( !$cacheVersion ) {
				$cacheVersion = (int)$cache->set( $key, 1, rand( 84600, 88200 ) );
			}

			// Now we've added our end key to the array, let's push it
			$cacheArray['version'] = $cacheVersion;

			$cdbw = \Cdb\Writer::open( $cdbFile );

			foreach ( $cacheArray as $key => $value ) {
				$cdbw->set( $key, $value );
			}

			$cdbw->close();

			return true;
		}

		return false;
	}

	public static function delete( string $wiki ) {
		global $wgCreateWikiCDBDirectory;

		if ( $wgCreateWikiCDBDirectory ) {
			$cache = ObjectCache::getLocalClusterInstance();
			$key = $cache->makeKey( 'CreateWiki', 'version' );
			$cache->delete( $key );

			return unlink( "$wgCreateWikiCDBDirectory/$wiki.cdb" );
		}
	}

	public static function changes() {
		global $wgCreateWikiCDBDirectory;

		if ( $wgCreateWikiCDBDirectory ) {
			$cache = ObjectCache::getLocalClusterInstance();
			$key = $cache->makeKey( 'CreateWiki', 'version' );
			$cache->incr( $key );
		}
	}

	public static function getDatabaseList( $list = 'all', $update = false ) {
		global $wgCreateWikiCDBDirectory, $wgCreateWikiDatabase;

		$cdbFile = "$wgCreateWikiCDBDirectory/databaseList.cdb";

		$cache = ObjectCache::getLocalClusterInstance();
		$key = $cache->makeGlobalKey( 'CreateWiki', 'dbVersion' );
		$cacheVersion = $cache->get( $key );

		if ( file_exists( $cdbFile ) ) {
			$cdbr = \Cdb\Reader::open( $cdbFile );
		}

		$cdbVersion = ( isset( $cdbr ) ) ? $cdbr->get( 'dbVersion' ) : null;

		if ( $update || !$cdbVersion || !( (int)$cdbVersion === (int)$cacheVersion ) ) {
			if ( $cacheVersion ) {
				$cacheVersion = (int)$cache->incr( $key );
			} else {
				$cacheVersion = (int)$cache->set( $key, 1, rand( 84600, 88200 ) );
			}

			$dbr = wfGetDB( DB_REPLICA, [], $wgCreateWikiDatabase );

			$all = [];
			$private = [];
			$closed = [];
			$inactive = [];

			$wikis = $dbr->select(
				'cw_wikis',
				[
					'wiki_dbname',
					'wiki_private',
					'wiki_closed',
					'wiki_inactive'
				],
				[],
				__METHOD__
			);

			foreach ( $wikis as $wiki ) {
				$all[] = $wiki->wiki_dbname;

				if ( $wiki->wiki_private ) {
					$private[] = $wiki->wiki_dbname;
				}

				if ( $wiki->wiki_closed ) {
					$closed[] = $wiki->wiki_dbname;
				}

				if ( $wiki->wiki_inactive ) {
					$inactive[] = $wiki->wiki_dbname;
				}
			}

			$cdbw = \Cdb\Writer::open( $cdbFile );
			$cdbw->set( 'dbVersion', $cacheVersion );
			$cdbw->set( 'all', json_encode( $all ) );
			$cdbw->set( 'private', json_encode( $private ) );
			$cdbw->set( 'closed', json_encode( $closed ) );
			$cdbw->set( 'inactive', json_encode( $inactive ) );
			$cdbw->close();

			$cdbr = \Cdb\Reader::open( $cdbFile );

		}

		if ( is_array( $list ) ) {
			$returnArray = [];

			foreach ( $list as $key ) {
				$returnArray[$key] = json_decode( $cdbr->get( $key ), true );
			}

			return $returnArray;
		}

		return json_decode( $cdbr->get( $list ), true );
	}

}
