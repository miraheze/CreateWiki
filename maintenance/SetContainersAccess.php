<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use Wikimedia\FileBackend\FileBackend;
use function print_r;

class SetContainersAccess extends Maintenance {

	private RemoteWikiFactory $remoteWikiFactory;

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Secure containers for wiki as defined in $wgCreateWikiContainers.' .
			' Also creates containers that don\'t exist.' );

		$this->requireExtension( 'CreateWiki' );
	}

	private function initServices(): void {
		$services = $this->getServiceContainer();
		$this->remoteWikiFactory = $services->get( 'RemoteWikiFactory' );
	}

	public function execute(): void {
		$this->initServices();

		$repo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		$backend = $repo->getBackend();

		$remoteWiki = $this->remoteWikiFactory->newInstance(
			$this->getConfig()->get( MainConfigNames::DBname )
		);

		$isPrivate = $remoteWiki->isPrivate();

		foreach ( $this->getConfig()->get( ConfigNames::Containers ) as $zone => $status ) {
			$dir = $backend->getContainerStoragePath( $zone );
			$private = $status === 'private';
			$publicPrivate = $status === 'public-private';
			$secure = ( $private || ( $publicPrivate && $isPrivate ) )
				? [ 'noAccess' => true, 'noListing' => true ] : [];

			$this->prepareDirectory( $backend, $dir, $secure );
		}
	}

	protected function prepareDirectory(
		FileBackend $backend,
		string $dir,
		array $secure
	): void {
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
			print_r( $status->getMessages( 'error' ) );
		}
	}
}

// @codeCoverageIgnoreStart
return SetContainersAccess::class;
// @codeCoverageIgnoreEnd
