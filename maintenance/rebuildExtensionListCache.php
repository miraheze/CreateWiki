<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use ExtensionProcessor;
use Maintenance;
use MediaWiki\MainConfigNames;
use Miraheze\CreateWiki\ConfigNames;

class RebuildExtensionListCache extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Rebuild or generate extension-list cache file (either JSON or PHP based on config).' );
		$this->addOption( 'cachedir',
			'Path to the cachedir to use, otherwise defaults to the value of $wgCreateWikiCacheDirectory.',
		false );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$queue = array_fill_keys( array_merge(
			glob( $this->getConfig()->get( MainConfigNames::ExtensionDirectory ) . '/*/extension*.json' ),
			glob( $this->getConfig()->get( MainConfigNames::StyleDirectory ) . '/*/skin.json' )
		), true );

		$processor = new ExtensionProcessor();
		foreach ( $queue as $path => $mtime ) {
			$processor->extractInfoFromFile( $path );
		}

		$data = $processor->getExtractedInfo();

		$list = array_column( $data['credits'], 'path', 'name' );

		$cacheDir = $this->getOption( 'cachedir', $this->getConfig()->get( ConfigNames::CacheDirectory ) );

		$this->generateCache( $cacheDir, $list );
	}

	private function generateCache( string $cacheDir, array $list ): void {
		$phpContent = "<?php\n\n" .
			"/**\n * Auto-generated extension list cache.\n */\n\n" .
			'return ' . var_export( $list, true ) . ";\n";

		file_put_contents( "{$cacheDir}/extension-list.php", $phpContent, LOCK_EX );
	}
}

$maintClass = RebuildExtensionListCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
