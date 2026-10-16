<?php

namespace Miraheze\CreateWiki\Maintenance;

use BackupReader;
use InitSiteStats;
use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use Psr\Log\LoggerInterface;
use RebuildTextIndex;
use RefreshLinks;
use Throwable;
use Wikimedia\FileBackend\FileBackend;
use Wikimedia\FileBackend\FSFile\FSFile;
use function file_exists;
use function is_readable;

class ImportLoadoutXmlDump extends Maintenance {

	private LoggerInterface $logger;

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Imports a CreateWiki loadout XML dump into this wiki.' );
		$this->addOption( 'xml',
			'The XML dump to import, either a local path or an mwstore:// storage path.',
			true, true
		);

		$this->requireExtension( 'CreateWiki' );
	}

	private function initServices(): void {
		$services = $this->getServiceContainer();
		$this->logger = $services->get( 'CreateWikiLogger' );
	}

	public function execute(): void {
		$this->initServices();

		$dbname = $this->getConfig()->get( MainConfigNames::DBname );
		$xmlPath = $this->getOption( 'xml' );

		$localFile = $this->getLocalFile( $dbname, $xmlPath );

		$this->logger->info(
			'Loadout import for {dbname} started.',
			[ 'dbname' => $dbname ]
		);

		try {
			$importDump = $this->createChild( BackupReader::class );
			$importDump->setOption( 'no-updates', true );
			// Author is always maintenance script. This should have no effect.
			$importDump->setOption( 'username-prefix', 'imported>' );
			$importDump->setArg( 0, $localFile?->getPath() ?? $xmlPath );
			$importDump->execute();

			$this->logger->info(
				'Loadout import for {dbname} finished importing the XML dump.',
				[ 'dbname' => $dbname ]
			);

			if ( !$this->getConfig()->get( MainConfigNames::DisableSearchUpdate ) ) {
				$rebuildText = $this->createChild( RebuildTextIndex::class );
				$rebuildText->execute();
				$this->logger->info(
					'Loadout import for {dbname} finished rebuildTextIndex.',
					[ 'dbname' => $dbname ]
				);
			}

			$rebuildLinks = $this->createChild( RefreshLinks::class );
			$rebuildLinks->execute();
			$this->logger->info(
				'Loadout import for {dbname} finished refreshLinks.',
				[ 'dbname' => $dbname ]
			);

			$siteStats = $this->createChild( InitSiteStats::class );
			$siteStats->setOption( 'update', true );
			$siteStats->setOption( 'active', true );
			$siteStats->execute();
			$this->logger->info(
				'Loadout import for {dbname} finished initSiteStats.',
				[ 'dbname' => $dbname ]
			);
		} catch ( Throwable $t ) {
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $t );

			$this->logger->error(
				'Loadout import for {dbname} failed: {exception}',
				[
					'dbname' => $dbname,
					'exception' => $t->getMessage(),
				]
			);

			$this->fatalError( $t->getMessage() );
		}

		$this->logger->info(
			'Loadout import for {dbname} finished.',
			[ 'dbname' => $dbname ]
		);
	}

	/**
	 * Resolve the configured dump location to something the importer can read.
	 *
	 * Storage paths (mwstore://) are fetched from their file backend into a
	 * temporary local file. Anything else is used as a local path as-is.
	 *
	 * @return FSFile|null The temporary file for a storage path, null for a local path.
	 */
	private function getLocalFile( string $dbname, string $xmlPath ): ?FSFile {
		if ( !FileBackend::isStoragePath( $xmlPath ) ) {
			if ( !file_exists( $xmlPath ) || !is_readable( $xmlPath ) ) {
				$this->fatalErrorWithLog( $dbname, $xmlPath, 'not found or not readable' );
			}

			return null;
		}

		$backend = $this->getServiceContainer()->getFileBackendGroup()->backendFromPath( $xmlPath );
		if ( $backend === null ) {
			$this->fatalErrorWithLog( $dbname, $xmlPath, 'has no known file backend' );
		}

		$localFile = $backend->getLocalReference( [ 'src' => $xmlPath ] );
		if ( !$localFile instanceof FSFile ) {
			$this->fatalErrorWithLog( $dbname, $xmlPath, 'could not be fetched from its file backend' );
		}

		return $localFile;
	}

	private function fatalErrorWithLog( string $dbname, string $xmlPath, string $problem ): never {
		$this->logger->error(
			'Loadout import for {dbname}: XML dump file {path} {problem}.',
			[
				'dbname' => $dbname,
				'path' => $xmlPath,
				'problem' => $problem,
			]
		);

		$this->fatalError( "XML dump file $xmlPath $problem." );
	}
}

// @codeCoverageIgnoreStart
return ImportLoadoutXmlDump::class;
// @codeCoverageIgnoreEnd
