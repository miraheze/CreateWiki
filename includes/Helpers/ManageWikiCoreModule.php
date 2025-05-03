<?php

namespace Miraheze\CreateWiki\Helpers;

use Miraheze\CreateWiki\ConfigNames;
use Miraheze\ManageWiki\ICoreModule;

class ManageWikiCoreModule extends RemoteWiki implements ICoreModule {

	public function isEnabled( string $feature ): bool {
		// Enable all features
		return true;
	}

	public function getCategoryOptions(): array {
		return $this->options->get( ConfigNames::Categories );
	}

	public function getDatabaseClusters(): array {
		return $this->options->get( ConfigNames::DatabaseClusters );
	}
}
