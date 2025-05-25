<?php

namespace Miraheze\CreateWiki\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use Miraheze\CreateWiki\Exceptions\MissingWikiError;
use Miraheze\CreateWiki\Helpers\ManageWikiCoreModule;
use Miraheze\CreateWiki\Helpers\RemoteWiki;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\CreateWikiDataFactory;
use Miraheze\ManageWiki\Exceptions\MissingWikiError as MWMissingWikiError;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Miraheze\ManageWiki\Hooks\ManageWikiCoreProviderHook;
use Miraheze\ManageWiki\ICoreModule;

class ManageWiki implements ManageWikiCoreProviderHook {

	public function __construct(
		private readonly Config $config,
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CreateWikiDataFactory $dataFactory,
		private readonly CreateWikiHookRunner $hookRunner,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory
	) {
	}

	/** @inheritDoc */
	public function onManageWikiCoreProvider( ?ICoreModule &$provider, string $dbname ): void {
		if ( $dbname === ModuleFactory::DEFAULT_DBNAME ) {
			// We don't need the core provider on 'default'
			return;
		}

		try {
			$provider = new ManageWikiCoreModule(
				$this->databaseUtils,
				$this->dataFactory,
				$this->hookRunner,
				$this->jobQueueGroupFactory,
				new ServiceOptions(
					RemoteWiki::CONSTRUCTOR_OPTIONS,
					$this->config
				),
				$dbname
			);
		} catch ( MissingWikiError $e ) {
			// Switch to the ManageWiki MissingWikiError since it
			// expects that one for ManageWiki.
			throw new MWMissingWikiError( $dbname );
		}
	}
}
