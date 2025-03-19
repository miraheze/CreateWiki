<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\ConfigNames;
use StatusValue;
use Wikimedia\FileBackend\FileBackend;

class SetContainersAccess extends Maintenance {

	private bool $isRetrying = false;
	private bool $needsRetry = false;

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Secure containers for wiki as defined in $wgCreateWikiContainers.' .
			' Also creates containers that don\'t exist.' );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$repo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		$backend = $repo->getBackend();

		$remoteWiki = $this->getServiceContainer()->get( 'RemoteWikiFactory' )->newInstance(
			$this->getConfig()->get( MainConfigNames::DBname )
		);

		$isPrivate = $remoteWiki->isPrivate();

		foreach ( $this->getConfig()->get( ConfigNames::Containers ) as $zone => $status ) {
			$dir = $backend->getContainerStoragePath( $zone );
			$private = $status === 'private';
			$publicPrivate = $status === 'public-private';
			$secure = ( $private || ( $publicPrivate && $isPrivate ) )
				? [ 'noAccess' => true, 'noListing' => true ] : [];

			$this->prepareDirectory( $backend, $dir, $zone, $secure );
		}

		if ( $this->needsRetry && !$this->isRetrying ) {
			$this->isRetrying = true;
			$this->needsRetry = false;
			$this->execute();
		}
	}

	private function prepareDirectory(
		FileBackend $backend,
		string $dir,
		string $zone,
		array $secure
	): void {
		// Create zone if it doesn't exist...
		$this->output( "Making sure '$dir' exists..." );
		$backend->clearCache( [ $dir ] );
		$status = $backend->prepare( [ 'dir' => $dir ] + $secure );

		if ( !$status->isOK() ) {
			$this->handleFailure( $dir, $zone, $status );
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
			$this->handleFailure( $dir, $zone, $status );
		} else {
			$this->output( "done.\n" );
		}
	}

	private function handleFailure(
		string $dir,
		string $zone,
		StatusValue $status
	): void {
		if ( $this->isRetrying ) {
			$this->output( "retry failed.\n" );
			$this->error( $status );
			return;
		}

		$this->output( "failed.\n" );
		$this->error( $status );

		$hookRunner = $this->getServiceContainer()->get( 'CreateWikiHookRunner' );
		if ( $hookRunner->onCreateWikiSetContainersAccessFailed( $dir, $zone ) ) {
			// If the hook returned true, we can try this script one time.
			$this->output( "retrying.\n" );
			$this->needsRetry = true;
		}
	}
}

// @codeCoverageIgnoreStart
return SetContainersAccess::class;
// @codeCoverageIgnoreEnd
