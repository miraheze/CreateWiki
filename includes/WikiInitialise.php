<?php

class WikiInitialise {
	public $config = null;
	public $hostname = null;
	public $dbname = null;
	public $missing = false;

	public function __construct() {
		// Safeguard LocalSettings from being accessed
		if ( !defined( 'MEDIAWIKI' ) ) {
			die( 'Not an entry point.' );
		}

		$this->config = new SiteConfiguration;
	}

	public function setVariables( string $cacheDir, array $suffixes, array $siteMatch ) {
		$this->cacheDir = $cacheDir;
		$this->config->suffixes = $suffixes;
		$this->hostname = $_SERVER['HTTP_HOST'] ?? 'undefined';

		// Let's fake a database list - default config should suffice
		if ( !file_exists( $this->cacheDir . '/databases.json' ) ) {
			$databasesArray = [
				'timestamp' => 0,
				'databases' => [],
				'domains' => []
			];
		} else {
			$databasesArray = json_decode( file_get_contents( $this->cacheDir . '/databases.json' ), true );
		}

		// Let's found out what the database name is!
		if ( defined( 'MW_DB' ) ) {
			$this->dbname = MW_DB;
		} elseif ( isset( $databasesArray['domains']['https://' . $this->hostname] ) ) {
			$this->dbname = $databasesArray['domains']['https://' . $this->hostname];
		} else {
			$explode = explode( '.', $this->hostname, 2 );

			if ( $explode[0] == 'www' ) {
				$explode = explode( '.', $explode[1], 2 );
			}

			foreach ( $siteMatch as $site => $suffix ) {
				if ( $explode[1] == $site ) {
					$this->dbname = $explode[0] . $suffix;
					break;
				}
			}
		}

		// We use this quite a bit. If we don't have one, something is wrong
		if ( is_null( $this->dbname ) ) {
			$this->missing = true;
		} elseif ( !count( $databasesArray['databases'] ) ) {
			$databasesArray['databases'][] = $this->dbname;
		}

		// As soon as we know the database name, let's assign it
		$this->config->settings['wgDBname'][$this->dbname] = $this->dbname;

		$this->config->wikis = $databasesArray['databases'];

		if ( !in_array( $this->dbname, $databasesArray['databases'] ) ) {
			$this->missing = true;
		}

		$suffixMatch = array_flip( $siteMatch );
		$domainsMatch = array_flip( $databasesArray['domains'] );
		$serverArray = [];

		// MediaWiki has a cross-wiki depedency in wikifarms. So we need to know what else exists here, but not their real domains - just accessible ones
		foreach ( $databasesArray['databases'] as $db ) {
			foreach ( $suffixes as $suffix ) {
				if ( substr( $db, -strlen( $suffix ) == $suffix ) ) {
					$serverArray[$db] = 'https://' . substr( $db, 0, -strlen( $suffix ) ) . '.' . $suffixMatch[$suffix];
				}
			}
		}

		$this->config->settings['wgServer'] = array_merge( $serverArray, $domainsMatch );
		$this->config->settings['wgServer']['default'] = 'https://example.org';

		// We need the CLI to be able to access 'deleted' wikis
		if ( PHP_SAPI == 'cli' && file_exists( $this->cacheDir . '/deleted.json' ) ) {
			$deletedDatabases = json_decode( file_get_contents( $this->cacheDir . '/deleted.json' ), true );

			$this->config->wikis = array_merge( $this->config->wikis, $deletedDatabases['databases'] );
		}

		// Now let's formalise our database list to the world
		$this->config->settings['wgLocalDatabases']['default'] = $this->config->wikis;
	}

	public function readCache() {
		// If we don't have a cache file, let us exit here
		if ( !file_exists( $this->cacheDir . '/' . $this->dbname . '.json' ) ) {
			return;
		}

		$cacheArray = json_decode( file_get_contents( $this->cacheDir . '/' . $this->dbname . '.json' ), true );

		// Assign top level variables first
		$this->config->settings['wgSitename'][$this->dbname] = $cacheArray['core']['wgSitename'];
		$this->config->settings['wgLanguageCode'][$this->dbname] = $cacheArray['core']['wgLanguageCode'];
		if ( $cacheArray['url'] ) {
			$this->config->settings['wgServer'][$this->dbname] = $cacheArray['url'];
		}

		// Assign states
		$this->config->settings['cwPrivate'][$this->dbname] = (bool)$cacheArray['states']['private'];
		$this->config->settings['cwClosed'][$this->dbname] = (bool)$cacheArray['states']['closed'];
		$this->config->settings['cwInactive'][$this->dbname] = ( $cacheArray['states']['inactive'] == 'exempt' ) ? 'exempt' : (bool)$cacheArray['states']['inactive'];

		// The following is ManageWiki additional code
		// If ManageWiki isn't installed, this does nothing

		// Assign settings
		if ( isset( $cacheArray['settings'] ) ) {
			foreach ( (array)$cacheArray['settings'] as $var => $val ) {
				$this->config->settings[$var][$this->dbname] = $val;
			}
		}

		// Assign extensions variables now
		if ( isset( $cacheArray['extensions'] ) ) {
			foreach ( (array)$cacheArray['extensions'] as $var ) {
				$this->config->settings[$var][$this->dbname] = true;
			}
		}

		// Handle namespaces - additional settings will be done in ManageWiki
		if ( isset( $cacheArray['namespaces'] ) ) {
			foreach ( (array)$cacheArray['namespaces'] as $name => $ns ) {
				$this->config->settings['wgExtraNamespaces'][$this->dbname][(int)$ns['id']] = $name;
				$this->config->settings['wgNamespacesToBeSearchedDefault'][$this->dbname][(int)$ns['id']] = $ns['searchable'];
				$this->config->settings['wgNamespacesWithSubpages'][$this->dbname][(int)$ns['id']] = $ns['subpages'];
				$this->config->settings['wgNamespaceContentModels'][$this->dbname][(int)$ns['id']] = $ns['contentmodel'];

				if ( $ns['content'] ) {
					$this->config->settings['wgContentNamespaces'][$this->dbname][] = $ns['id'];
				}

				if ( $ns['protection'] ) {
					$this->config->settings['wgNamespaceProtection'][$this->dbname][(int)$ns['id']] = [ $ns['protection'] ];
				}

				foreach ( (array)$ns['aliases'] as $alias ) {
					$this->config->settings['wgNamespaceAliases'][$this->dbname][$alias] = (int)$ns['id'];
				}
			}
		}

		// Handle Permissions
		if ( isset( $cacheArray['permissions'] ) ) {
			foreach ( (array)$cacheArray['permissions'] as $group => $perm ) {
				foreach ( (array)$perm['permissions'] as $id => $right ) {
					$this->config->settings['wgGroupPermissions'][$this->dbname][$group][$right] = true;
				}

				foreach ( (array)$perm['addgroups'] as $name ) {
					$this->config->settings['wgAddGroups'][$this->dbname][$group][] = $name;
				}

				foreach ( (array)$perm['removegroups'] as $name ) {
					$this->config->settings['wgRemoveGroups'][$this->dbname][$group][] = $name;
				}

				foreach ( (array)$perm['addself'] as $name ) {
					$this->config->settings['wgGroupsAddToSelf'][$this->dbname][$group][] = $name;
				}

				foreach ( (array)$perm['removeself'] as $name ) {
					$this->config->settings['wgGroupsRemoveFromSelf'][$this->dbname][$group][] = $name;
				}

				if ( !is_null( $perm['autopromote'] ) ) {
					$onceId = array_search( 'once', $perm['autopromote'] );

					if ( !is_bool( $onceId ) ) {
						unset( $perm['autopromote'][$onceId] );
						$promoteVar = 'wgAutopromoteOnce';
					} else {
						$promoteVar = 'wgAutopromote';
					}

					$this->config->settings[$promoteVar][$this->dbname][$group] = $perm['autopromote'];
				}
			}
		}
	}
}
