<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Deferred\SiteStatsUpdate;
use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\FakeMaintenance;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Shell\Shell;
use MediaWiki\SiteStats\SiteStatsInit;
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

	public function execute(): void {
		$logger = $this->getServiceContainer()->get( 'CreateWikiLogger' );
		'@phan-var LoggerInterface $logger';
		$this->logger = $logger;
		$this->logger->info( 'Loadout import started.' );

		$xmlPath = $this->getOption( 'xml' );

		if ( !file_exists( $xmlPath ) || !is_readable( $xmlPath ) ) {
			$this->fatalError( "XML dump file $xmlPath not found or not readable." );
		}

		$dbw = $this->getPrimaryDB();

		try {
			$result = Shell::makeScriptCommand(
				'importDump.php',
				[
					'--no-updates',
					'--no-local-users',
					'--wiki', $this->getConfig()->get( MainConfigNames::DBname ),
					$xmlPath,
				]
			)->limits( [
				'memory' => 0,
				'filesize' => 0,
				'time' => 0,
				'walltime' => 0,
			] )->execute();

			if ( $result->getExitCode() !== 0 ) {
				$this->fatalError( 'Loadout import failed to import the dump file: ' . $result->getStderr() );
			}

			$this->logger->info( 'Loadout import finished importing the XML dump.' );

			$siteStatsInit = new SiteStatsInit();
			$siteStatsInit->refresh();

			SiteStatsUpdate::cacheUpdate( $dbw );
			$this->logger->info( 'Loadout import finished updateSiteStats.' );

			$maintenance = new FakeMaintenance;
			$rebuildText = $maintenance->createChild( RebuildTextIndex::class );
			$rebuildText->execute();
			$this->logger->info( 'Loadout import finished rebuildTextIndex.' );

			$rebuildLinks = $maintenance->createChild( RefreshLinks::class );
			$rebuildLinks->execute();
			$this->logger->info( 'Loadout import finished refreshLinks.' );
		} catch ( Throwable $t ) {
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $t );
			$this->fatalError( $t->getMessage() );
		}

		$this->logger->info( 'Loadout import finished.' );
	}
}

// @codeCoverageIgnoreStart
return ImportLoadoutXmlDump::class;
// @codeCoverageIgnoreEnd
