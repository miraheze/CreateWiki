<?php

namespace Miraheze\CreateWiki\Helpers;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Exceptions\MissingWikiError;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\CreateWikiDataStore;
use Miraheze\ManageWiki\Exceptions\MissingWikiError as ManageWikiMissingWikiError;
use Miraheze\ManageWiki\ICoreModule;

class ManageWikiCoreModule extends RemoteWiki implements ICoreModule {

	/** @throws ManageWikiMissingWikiError */
	public static function factory(
		string $dbname,
		CreateWikiDatabaseUtils $databaseUtils,
		CreateWikiDataStore $dataStore,
		CreateWikiHookRunner $hookRunner,
		JobQueueGroupFactory $jobQueueGroupFactory,
		ServiceOptions $options
	): self {
		try {
			return new self(
				$databaseUtils,
				$dataStore,
				$hookRunner,
				$jobQueueGroupFactory,
				$options,
				$dbname
			);
		} catch ( MissingWikiError ) {
			throw new ManageWikiMissingWikiError( $dbname );
		}
	}

	public function isEnabled( string $feature ): bool {
		$enabled = [
			'closed-wikis' => $this->options->get( ConfigNames::UseClosedWikis ),
			'experimental-wikis' => $this->options->get( ConfigNames::UseExperimental ),
			'inactive-wikis' => $this->options->get( ConfigNames::UseInactiveWikis ),
			'private-wikis' => $this->options->get( ConfigNames::UsePrivateWikis ),
		];
		// Enable all other features
		return $enabled[$feature] ?? true;
	}

	public function getCategoryOptions(): array {
		return $this->options->get( ConfigNames::Categories );
	}

	public function getDatabaseClusters(): array {
		return $this->options->get( ConfigNames::DatabaseClusters );
	}

	public function getDatabaseClustersInactive(): array {
		return $this->options->get( ConfigNames::DatabaseClustersInactive );
	}

	public function getInactiveExemptExpiryOptions(): array {
		return $this->options->get( ConfigNames::InactiveExemptExpiryOptions );
	}

	public function getInactiveExemptReasonOptions(): array {
		return $this->options->get( ConfigNames::InactiveExemptReasonOptions );
	}

	public function getClosedReasonOptions(): array {
		return $this->options->get( ConfigNames::ClosedReasonOptions );
	}
}
