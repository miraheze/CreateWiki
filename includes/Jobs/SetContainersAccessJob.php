<?php

namespace Miraheze\CreateWiki\Jobs;

use Job;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use RepoGroup;

class SetContainersAccessJob extends Job {

	public const JOB_NAME = 'SetContainersAccessJob';

	private Config $config;
	private RepoGroup $repoGroup;

	private bool $isPrivate;

	public function __construct(
		array $params,
		ConfigFactory $configFactory,
		RepoGroup $repoGroup
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->isPrivate = $params['private'];

		$this->config = $configFactory->makeConfig( 'CreateWiki' );
		$this->repoGroup = $repoGroup;
	}

	public function run(): bool {
		// Make sure all of the file repo zones are setup
		$repo = $this->repoGroup->getLocalRepo();

		$backend = $repo->getBackend();
		foreach ( $this->config->get( 'CreateWikiContainers' ) as $zone => $status ) {
			$dir = $backend->getContainerStoragePath( $zone );
			$private = $status === 'private';
			$publicPrivate = $status === 'public-private';
			$secure = ( $private || ( $publicPrivate && $this->isPrivate ) )
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
