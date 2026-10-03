<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\ConfigNames;
use Wikimedia\FileBackend\FileBackend;
use function dirname;
use function file_exists;
use function is_readable;

class StoreLoadoutXmlDump extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Stores an XML dump at the location configured for a CreateWiki loadout.'
		);

		$this->addOption( 'loadout', 'Name of the loadout to store the XML dump for.', withArg: true );
		$this->addOption(
			'destination',
			'Destination path to store the loadout. Use this instead of --loadout if you want to ' .
			'have the loadout XML dump ready in the Swift backend before making config changes.',
			withArg: true );
		$this->addOption( 'file', 'Path to the local XML dump to store.', true, true );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$sourceFile = $this->getOption( 'file' );
		if ( !file_exists( $sourceFile ) || !is_readable( $sourceFile ) ) {
			$this->fatalError( "XML dump file $sourceFile not found or not readable." );
		}

		if ( $this->hasOption( 'loadout' ) ) {
			$destination = $this->getDestination( $this->getOption( 'loadout' ) );
		} elseif ( $this->hasOption( 'destination' ) ) {
			$destination = $this->getOption( 'destination' );
		} else {
			$this->fatalError( 'Either --loadout or --destination is required.' );
		}

		if ( FileBackend::isStoragePath( $destination ) ) {
			$this->storeToSwift( $sourceFile, $destination );
		} else {
			$this->fatalError(
				"The destination $destination is not a swift container. " .
				"You do not need to run this script to store it."
			);
		}

		$this->output( "Stored $sourceFile at $destination.\n" );
	}

	private function getDestination( string $loadout ): string {
		$loadouts = $this->getConfig()->get( ConfigNames::LoadoutConfigs );
		if ( !isset( $loadouts[$loadout] ) ) {
			$this->fatalError(
				"Unknown loadout $loadout."
			);
		}

		$destination = $loadouts[$loadout]['xml'] ?? '';
		if ( !$destination ) {
			$this->fatalError( "Loadout $loadout does not configure an XML dump." );
		}

		return $destination;
	}

	private function storeToSwift( string $file, string $destination ): void {
		$backend = $this->getServiceContainer()->getFileBackendGroup()->backendFromPath( $destination );
		if ( $backend === null ) {
			$this->fatalError( "Storage path $destination has no known file backend." );
		}

		$status = $backend->prepare( [ 'dir' => dirname( $destination ) ] );
		if ( !$status->isOK() ) {
			$this->fatalError( $status );
		}

		$status = $backend->quickStore( [
			'src' => $file,
			'dst' => $destination,
			'overwrite' => true,
		] );

		if ( !$status->isOK() ) {
			$this->fatalError( $status );
		}
	}
}

// @codeCoverageIgnoreStart
return StoreLoadoutXmlDump::class;
// @codeCoverageIgnoreEnd
