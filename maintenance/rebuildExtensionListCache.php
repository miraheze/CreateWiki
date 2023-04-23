<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use ExtensionProcessor;
use Maintenance;
use MediaWiki\MediaWikiServices;

class RebuildExtensionListCache extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Rebuild or generate extension-list.json cache file.' );
		$this->addOption( 'cachedir', 'Path to the cachedir to use, otherwise defaults to the value of $wgCreateWikiCacheDirectory.', false );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );

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

		$cacheDir = $this->getOption( 'cachedir', $config->get( 'CreateWikiCacheDirectory' ) );
		file_put_contents( "{$cacheDir}/extension-list.json", json_encode( $list ), LOCK_EX );
	}
}

$maintClass = RebuildExtensionListCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
