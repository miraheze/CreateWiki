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
use function file_exists;
use function is_readable;

class ImportLoadoutXmlDump extends Maintenance {

	private LoggerInterface $logger;

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Imports a CreateWiki loadout XML dump into this wiki.' );
		$this->addOption( 'xml', 'Path to the XML dump to import.', true, true );

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

		if ( !file_exists( $xmlPath ) || !is_readable( $xmlPath ) ) {
			$this->logger->error(
				'Loadout import for {dbname}: XML dump file {path} not found or not readable.',
				[
					'dbname' => $dbname,
					'path' => $xmlPath,
				]
			);

			$this->fatalError( "XML dump file $xmlPath not found or not readable." );
		}

		$this->logger->info(
			'Loadout import for {dbname} started.',
			[ 'dbname' => $dbname ]
		);

		try {
			$importDump = $this->createChild( BackupReader::class );
			$importDump->setOption( 'no-updates', true );
			// Author is always maintenance script. This should have no effect.
			$importDump->setOption( 'username-prefix', 'imported>' );
			$importDump->setArg( 0, $xmlPath );
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
}

// @codeCoverageIgnoreStart
return ImportLoadoutXmlDump::class;
// @codeCoverageIgnoreEnd
