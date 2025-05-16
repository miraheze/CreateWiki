<?php

namespace Miraheze\CreateWiki\Helpers;

use Miraheze\CreateWiki\ConfigNames;
use Miraheze\ManageWiki\ICoreModule;

class ManageWikiCoreModule extends RemoteWiki implements ICoreModule {

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

	public function getInactiveExemptReasonOptions(): array {
		return $this->options->get( ConfigNames::InactiveExemptReasonOptions );
	}
}
