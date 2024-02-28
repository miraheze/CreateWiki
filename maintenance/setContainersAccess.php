<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use FileBackend;
use Maintenance;
use MediaWiki\MainConfigNames;
use Miraheze\CreateWiki\RemoteWiki;

class SetContainersAccess extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Secure containers for wiki as defined in $wgCreateWikiContainers.' .
			' Also creates containers that don\'t exist.' );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$repo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		$backend = $repo->getBackend();

		$wiki = new RemoteWiki( $this->getConfig()->get( MainConfigNames::DBname ) );
		$isPrivate = $wiki->isPrivate();

		foreach ( $this->getConfig()->get( 'CreateWikiContainers' ) as $zone => $status ) {
			$dir = $backend->getContainerStoragePath( $zone );
			$private = $status === 'private';
			$publicPrivate = $status === 'public-private';
			$secure = ( $private || $publicPrivate && $isPrivate )
				? [ 'noAccess' => true, 'noListing' => true ] : [];

			$this->prepareDirectory( $backend, $dir, $secure );
		}
	}

	protected function prepareDirectory( FileBackend $backend, $dir, array $secure ) {
		// Create zone if it doesn't exist...
		$this->output( "Making sure '$dir' exists..." );
		$backend->clearCache( [ $dir ] );
		$status = $backend->prepare( [ 'dir' => $dir ] + $secure );

		if ( !$status->isOK() ) {
			$this->output( 'failed...' );
		}

		// Make sure zone has the right ACLs...
		if ( $secure ) {
			// private
			$this->output( 'making private...' );
			$status->merge( $backend->secure( [ 'dir' => $dir ] + $secure ) );
		} else {
			// public
			$this->output( 'making public...' );
			$status->merge( $backend->publish( [ 'dir' => $dir, 'access' => true ] ) );
		}

		if ( $status->isOK() ) {
			$this->output( "done.\n" );
		} else {
			$this->output( "failed.\n" );
			print_r( $status->getErrors() );
		}
	}
}

$maintClass = SetContainersAccess::class;
require_once RUN_MAINTENANCE_IF_MAIN;
