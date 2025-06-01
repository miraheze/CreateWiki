<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Registration\ExtensionProcessor;
use Miraheze\CreateWiki\ConfigNames;
use function array_column;
use function array_fill_keys;
use function array_merge;
use function file_put_contents;
use function glob;
use function var_export;
use const LOCK_EX;

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

// @codeCoverageIgnoreStart
return RebuildExtensionListCache::class;
// @codeCoverageIgnoreEnd
