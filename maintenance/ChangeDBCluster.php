<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use function feof;
use function fgets;
use function fopen;
use function trim;

class ChangeDBCluster extends Maintenance {

	private RemoteWikiFactory $remoteWikiFactory;

	public function __construct() {
		parent::__construct();

		$this->addOption( 'db-cluster', 'Sets the wikis requested to a different db cluster.', true, true );

		$this->addOption( 'file', 'Path to file where the wikinames are store. ' .
			'Must be one wikidb name per line. (Optional, fallsback to current dbname)',
			false, true
		);

		$this->requireExtension( 'CreateWiki' );
	}

	private function initServices(): void {
		$services = $this->getServiceContainer();
		$this->remoteWikiFactory = $services->get( 'RemoteWikiFactory' );
	}

	public function execute(): void {
		$this->initServices();
		if ( $this->getOption( 'file' ) ) {
			$file = fopen( $this->getOption( 'file' ), 'r' );

			if ( !$file ) {
				$this->fatalError( 'Unable to read file, exiting' );
			}
		} else {
			$remoteWiki = $this->remoteWikiFactory->newInstance(
				$this->getConfig()->get( MainConfigNames::DBname )
			);

			$remoteWiki->setDBCluster( $this->getOption( 'db-cluster' ) );
			$remoteWiki->commit();
			return;
		}

		for ( $linenum = 1; !feof( $file ); $linenum++ ) {
			$line = trim( fgets( $file ) );

			if ( $line === '' ) {
				continue;
			}

			$remoteWiki = $this->remoteWikiFactory->newInstance( $line );
			$remoteWiki->setDBCluster( $this->getOption( 'db-cluster' ) );
			$remoteWiki->commit();
		}
	}
}

// @codeCoverageIgnoreStart
return ChangeDBCluster::class;
// @codeCoverageIgnoreEnd
