<?php

namespace Miraheze\CreateWiki\Jobs;

use MediaWiki\JobQueue\Job;
use Miraheze\CreateWiki\Services\CacheUpdate;

class CacheUpdateJob extends Job {

	public const string JOB_NAME = 'CreateWikiCacheUpdateJob';

	private readonly ?string $data;

	public function __construct(
		array $params,
		private readonly CacheUpdate $cacheUpdate,
	) {
		parent::__construct( self::JOB_NAME, $params );
		$this->data = $params['data'] ?? null;
	}

	public function run(): bool {
		return $this->cacheUpdate->executeNow( $this->data );
	}
}
