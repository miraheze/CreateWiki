<?php

namespace Miraheze\CreateWiki\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use Miraheze\CreateWiki\Helpers\RemoteWiki;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;

class RemoteWikiFactory {

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CreateWikiDataStore $dataStore,
		private readonly CreateWikiHookRunner $hookRunner,
		private readonly CreateWikiValidator $validator,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly ServiceOptions $options
	) {
	}

	public function newInstance( string $dbname ): RemoteWiki {
		return new RemoteWiki(
			$this->databaseUtils,
			$this->dataStore,
			$this->hookRunner,
			$this->validator,
			$this->jobQueueGroupFactory,
			$this->options,
			$dbname
		);
	}
}
