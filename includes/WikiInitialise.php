<?php

class WikiInitialise {
	private $cacheDir;
	public $config;
	public $hostname;
	public $dbname;
	public $server;
	public $sitename;
	public $realms;
	public $missing = false;
	public $wikiDBClusters = [];
	public $disabledExtensions = [];

	public function __construct() {
		// Safeguard LocalSettings from being accessed
		if ( !defined( 'MEDIAWIKI' ) ) {
			die( 'Not an entry point.' );
		}

		$this->config = new SiteConfiguration;
	}

	public function setVariables( string $cacheDir, array $suffixes, array $siteMatch, array $realms = [] ) {
		$this->cacheDir = $cacheDir;
		$this->config->suffixes = $suffixes;
		$this->hostname = $_SERVER['HTTP_HOST'] ?? 'undefined';
		$this->realms = $realms;

		// Let's fake a database list - default config should suffice
		if ( !file_exists( $this->cacheDir . '/databases.json' ) ) {
			$databasesArray = [
				'timestamp' => 0,
				'combi' => []
			];
		} else {
			$databasesArray = json_decode( file_get_contents( $this->cacheDir . '/databases.json' ), true ) ?: [
				'timestamp' => 0,
				'combi' => []
			];
		}

		if ( !file_exists( $this->cacheDir . '/deleted.json' ) ) {
			$deletedDatabases = [
				'databases' => []
			];
		} else {
			$deletedDatabases = json_decode( file_get_contents( $this->cacheDir . '/deleted.json' ), true ) ?: [
				'databases' => []
			];
		}

		// Assign all known wikis
		$this->config->wikis = array_keys( $databasesArray['combi'] );

		// Handle wgServer and wgSitename
		$suffixMatch = array_flip( $siteMatch );
		$this->config->settings['wgServer']['default'] = 'https://' . $suffixMatch[ array_key_first( $suffixMatch ) ];
		$this->config->settings['wgSitename']['default'] = 'No sitename set.';

		foreach ( $databasesArray['combi'] as $db => $data ) {
			foreach ( $suffixes as $suffix ) {
				if ( substr( $db, -strlen( $suffix ) ) == $suffix ) {
					$this->config->settings['wgServer'][$db] = $data['u'] ?? 'https://' . substr( $db, 0, -strlen( $suffix ) ) . '.' . $suffixMatch[$suffix];
				}
			}

			$this->config->settings['wgSitename'][$db] = $data['s'];
			$this->wikiDBClusters[$db] = $data['c'];
		}

		foreach ( $deletedDatabases['databases'] as $db => $data ) {
			$this->config->settings['wgSitename'][$db] = $data['s'];
			$this->wikiDBClusters[$db] = $data['c'];
		}

		// We need the CLI to be able to access 'deleted' wikis
		if ( PHP_SAPI == 'cli' && file_exists( $this->cacheDir . '/deleted.json' ) ) {
			$this->config->wikis = array_merge( $this->config->wikis, array_keys( $deletedDatabases['databases'] ) );
		}

		// Now let's formalise our database list to the world
		$this->config->settings['wgLocalDatabases']['default'] = $this->config->wikis;

		// Let's found out what the database name is!
		if ( defined( 'MW_DB' ) ) {
			$this->dbname = MW_DB;
		} elseif ( defined( 'CW_DB' ) ) {
			$this->dbname = CW_DB;
		} elseif ( isset( array_flip( $this->config->settings['wgServer'] )['https://' . $this->hostname] ) ) {
			$this->dbname = array_flip( $this->config->settings['wgServer'] )['https://' . $this->hostname];
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
		if ( $this->dbname === null ) {
			$this->missing = true;
		} elseif ( !count( $databasesArray['combi'] ) ) {
			$databasesArray['combi'][$this->dbname] = [];
		}

		// As soon as we know the database name, let's assign it
		$this->config->settings['wgDBname'][$this->dbname] = $this->dbname;

		$this->server = $this->config->settings['wgServer'][$this->dbname] ?? $this->config->settings['wgServer']['default'];
		$this->sitename = $this->config->settings['wgSitename'][$this->dbname] ?? $this->config->settings['wgSitename']['default'];

		if ( !in_array( $this->dbname, $this->config->wikis ) ) {
			$this->missing = true;
		}
	}

	public function readCache() {
		// If we don't have a cache file, let us exit here
		if ( !file_exists( $this->cacheDir . '/' . $this->dbname . '.json' ) ) {
			return;
		}

		$cacheArray = json_decode( file_get_contents( $this->cacheDir . '/' . $this->dbname . '.json' ), true ) ?? [];

		// Assign top level variables first
		$this->config->settings['wgSitename'][$this->dbname] = $cacheArray['core']['wgSitename'] ?? $this->config->settings['wgSitename']['default'];
		$this->config->settings['wgLanguageCode'][$this->dbname] = $cacheArray['core']['wgLanguageCode'] ?? 'en';
		if ( isset( $cacheArray['url'] ) && $cacheArray['url'] ) {
			$this->config->settings['wgServer'][$this->dbname] = $cacheArray['url'];
		}

		// Assign states
		$this->config->settings['cwPrivate'][$this->dbname] = (bool)$cacheArray['states']['private'];
		$this->config->settings['cwClosed'][$this->dbname] = (bool)$cacheArray['states']['closed'];
		$this->config->settings['cwInactive'][$this->dbname] = ( ( $cacheArray['states']['inactive'] ?? false ) == 'exempt' ) ? 'exempt' : (bool)$cacheArray['states']['inactive'];
		$this->config->settings['cwExperimental'][$this->dbname] = (bool)( $cacheArray['states']['experimental'] ?? false );

		$server = $this->config->settings['wgServer'][$this->dbname] ?? $this->config->settings['wgServer']['default'];
		$tags = [];

		foreach ( $this->realms as $realmServer => $tag ) {
			if ( preg_match( '/^(.*).' . $realmServer . '$/', $server ) ) {
				$tags[] = $tag;
			}
		}

		foreach ( ( $cacheArray['states'] ?? [] ) as $state => $value ) {
			if ( $value !== 'exempt' && (bool)$value ) {
				$tags[] = $state;
			}
		}

		$this->config->siteParamsCallback = static function () use ( $cacheArray, $tags ) {
			return [
				'suffix' => null,
				'lang' => $cacheArray['core']['wgLanguageCode'] ?? 'en',
				'tags' => array_merge( ( $cacheArray['extensions'] ?? [] ), $tags ),
				'params' => []
			];
		};

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
					$this->config->settings['wgContentNamespaces'][$this->dbname][] = (int)$ns['id'];
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

				if ( $perm['autopromote'] !== null ) {
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

	public function loadExtensions() {
		// If we don't have a cache file, let us exit here
		if ( !file_exists( $this->cacheDir . '/' . $this->dbname . '.json' ) ) {
			return;
		}

		$cacheArray = json_decode( file_get_contents( $this->cacheDir . '/' . $this->dbname . '.json' ), true );

		$config = new GlobalVarConfig( 'wg' );

		if ( !file_exists( "{$this->cacheDir}/extension-list.json" ) ) {
			$queue = array_fill_keys( array_merge(
					glob( $config->get( 'ExtensionDirectory' ) . '/*/extension*.json' ),
					glob( $config->get( 'StyleDirectory' ) . '/*/skin.json' )
				),
			true );

			$processor = new ExtensionProcessor();

			foreach ( $queue as $path => $mtime ) {
				$json = file_get_contents( $path );
				$info = json_decode( $json, true );
				$version = $info['manifest_version'] ?? 2;

				$processor->extractInfo( $path, $info, $version );
			}

			$data = $processor->getExtractedInfo();

			$list = array_column( $data['credits'], 'path', 'name' );

			file_put_contents( "{$this->cacheDir}/extension-list.json", json_encode( $list ), LOCK_EX );
		} else {
			$list = json_decode( file_get_contents( "{$this->cacheDir}/extension-list.json" ), true );
		}

		if ( isset( $cacheArray['extensions'] ) ) {
			foreach ( $config->get( 'ManageWikiExtensions' ) as $name => $ext ) {
				$this->config->settings[ $ext['var'] ]['default'] = false;

				if ( in_array( $ext['var'], (array)$cacheArray['extensions'] ) &&
					!in_array( $name, $this->disabledExtensions )
				) {
					$path = $list[ $ext['name'] ] ?? false;

					$pathInfo = pathinfo( $path )['extension'] ?? false;
					if ( $path && $pathInfo === 'json' ) {
						ExtensionRegistry::getInstance()->queue( $path );
					}
				}
			}
		}
	}
}
