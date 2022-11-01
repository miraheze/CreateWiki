<?php

namespace Miraheze\CreateWiki;

use GenericParameterJob;
use Job;
use MediaWiki\MediaWikiServices;

class SetContainersAccessJob extends Job implements GenericParameterJob {

	/** @var bool */
	private $isPrivate;

	public function __construct( array $params ) {
		parent::__construct( 'SetContainersAccessJob', $params );

		$this->isPrivate = $params['private'];
	}

	public function run() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );

		// Make sure all of the file repo zones are setup
		$repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();

		$backend = $repo->getBackend();
		foreach ( [ 'public', 'thumb', 'transcoded', 'temp', 'deleted' ] as $zone ) {
			$dir = $repo->getZonePath( $zone );
			$secure = ( $config->get( 'CreateWikiUseSecureContainers' ) &&
				( $zone === 'deleted' || $zone === 'temp' || $this->isPrivate )
			) ? [ 'noAccess' => true, 'noListing' => true ] : [];

			$backend->clearCache( [ $dir ] );
			$backend->prepare( [ 'dir' => $dir ] + $secure );

			if ( $secure ) {
				$backend->secure( [ 'dir' => $dir ] + $secure );
				continue;
			}

			$backend->publish( [ 'dir' => $dir, 'access' => true ] );
		}

		if (
			$this->isPrivate &&
			$config->get( 'CreateWikiUseSecureContainers' ) &&
			$config->get( 'CreateWikiExtraSecuredContainers' )
		) {
			foreach ( $config->get( 'CreateWikiExtraSecuredContainers' ) as $container ) {
				$dir = $backend->getContainerStoragePath( $container );
				$backend->prepare( [ 'dir' => $dir, 'noAccess' => true, 'noListing' => true ] );
				$backend->secure( [ 'dir' => $dir, 'noAccess' => true, 'noListing' => true ] );
			}
		}

		return true;
	}
}
