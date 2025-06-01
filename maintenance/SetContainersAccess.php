<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use StatusValue;
use Wikimedia\FileBackend\FileBackend;

class SetContainersAccess extends Maintenance {

	private CreateWikiHookRunner $hookRunner;
	private RemoteWikiFactory $remoteWikiFactory;

	private bool $isRetrying = false;
	private bool $needsRetry = false;

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Secure containers for wiki as defined in $wgCreateWikiContainers.' .
			' Also creates containers that don\'t exist.' );

		$this->requireExtension( 'CreateWiki' );
	}

	private function initServices(): void {
		$services = $this->getServiceContainer();
		$this->hookRunner = $services->get( 'CreateWikiHookRunner' );
		$this->remoteWikiFactory = $services->get( 'RemoteWikiFactory' );
	}

	public function execute(): void {
		$this->initServices();
		$this->processContainers();

		if ( $this->needsRetry && !$this->isRetrying ) {
			$this->isRetrying = true;
			$this->needsRetry = false;

			$this->processContainers();
		}
	}

	private function processContainers(): void {
		$repo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		$backend = $repo->getBackend();

		$remoteWiki = $this->remoteWikiFactory->newInstance(
			$this->getConfig()->get( MainConfigNames::DBname )
		);

		$isPrivate = $remoteWiki->isPrivate();
		foreach ( $this->getConfig()->get( ConfigNames::Containers ) as $zone => $state ) {
			$dir = $backend->getContainerStoragePath( $zone );

			$private = $state === 'private';
			$publicPrivate = $state === 'public-private';

			$secure = ( $private || ( $publicPrivate && $isPrivate ) )
				? [ 'noAccess' => true, 'noListing' => true ] : [];

			$this->prepareDirectory( $backend, $secure, $dir, $zone );
		}
	}

	private function prepareDirectory(
		FileBackend $backend,
		array $secure,
		string $dir,
		string $zone
	): void {
		// Create zone if it doesn't exist...
		$this->output( "Making sure '$dir' exists..." );
		$backend->clearCache( [ $dir ] );
		$status = $backend->prepare( [ 'dir' => $dir ] + $secure );

		if ( !$status->isOK() ) {
			$this->handleFailure( $status, $dir, $zone );
			return;
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

		if ( !$status->isOK() ) {
			$this->handleFailure( $status, $dir, $zone );
			return;
		}

		$this->output( "done.\n" );
	}

	private function handleFailure(
		StatusValue $status,
		string $dir,
		string $zone
	): void {
		if ( $this->isRetrying ) {
			$this->output( "retry failed.\n" );
			$this->error( $status );
			return;
		}

		$this->output( "failed.\n" );
		$this->error( $status );

		if ( $this->hookRunner->onCreateWikiSetContainersAccessFailed( $dir, $zone ) ) {
			// If the hook returned true, we can try this script one time.
			$this->output( "retrying.\n" );
			$this->needsRetry = true;
		}
	}
}

// @codeCoverageIgnoreStart
return SetContainersAccess::class;
// @codeCoverageIgnoreEnd
