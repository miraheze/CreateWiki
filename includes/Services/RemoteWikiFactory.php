<?php

namespace Miraheze\CreateWiki\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;

class RemoteWikiFactory {

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CreateWikiDataFactory $dataFactory,
		private readonly CreateWikiHookRunner $hookRunner,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly ServiceOptions $options
	) {
	}

	public function newInstance( string $dbname ): RemoteWikiFactory {
		return new RemoteWikiFactory(
			$this->databaseUtils,
			$this->dataFactory,
			$this->hookRunner,
			$this->jobQueueGroupFactory,
			$this->options,
			$dbname
		);
	}
}
