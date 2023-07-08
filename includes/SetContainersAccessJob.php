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
		foreach ( $config->get( 'CreateWikiContainers' ) as $zone => $status ) {
			$dir = $repo->getZonePath( $zone );
			$secure = ( $status['private'] || $status['public-private'] && $this->isPrivate )
				? [ 'noAccess' => true, 'noListing' => true ] : [];

			$backend->clearCache( [ $dir ] );
			$backend->prepare( [ 'dir' => $dir ] + $secure );

			if ( $secure ) {
				$backend->secure( [ 'dir' => $dir ] + $secure );
				continue;
			}

			$backend->publish( [ 'dir' => $dir, 'access' => true ] );
		}

		return true;
	}
}
