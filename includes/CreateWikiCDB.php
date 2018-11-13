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

	public static function get( string $wiki, string $section ) {
		global $wgCreateWikiCDBDirectory;

		if ( $wgCreateWikiCDBDirectory ) {
			$cdbfile = "$wgCreateWikiCDBDirectory/$wiki.cdb";

			if ( file_exists( $cdbfile ) ) {
				$cdbr = \Cdb\Reader::open( $cdbfile );

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

			// If we have a key, let's increase it! If not, add one!
			if ( $cacheVersion ) {
				$cacheVersion = (int)$cache->incr( $key );
			} else {
				$cacheVersion = (int)$cache->set( $key, 1, rand( 84600, 88200 ) );
			}

			// Now we've added our end key to the array, let's push it
			$cacheArray['version'] = $cacheVersion;

			$cdbw = \Cdb\Writer::open( "$wgCreateWikiCDBDirectory/$wiki.cdb" );

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
}
