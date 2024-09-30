<?php

namespace Miraheze\CreateWiki\Hooks\Handlers;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class Installer implements LoadExtensionSchemaUpdatesHook {

	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = __DIR__ . '/../../../sql';

		$updater->addExtensionTable(
			'cw_requests',
			"$dir/cw_requests.sql"
		);

		$updater->addExtensionTable(
			'cw_comments',
			"$dir/cw_comments.sql"
		);

		$updater->addExtensionTable(
			'cw_wikis',
			"$dir/cw_wikis.sql"
		);

		$updater->addExtensionField(
			'cw_requests',
			'cw_locked',
			"$dir/patches/patch-cw_requests-add-cw_locked.sql"
		);

		$updater->addExtensionField(
			'cw_requests',
			'cw_extra',
			"$dir/patches/patch-cw_requests-add-cw_extra.sql"
		);

		$updater->modifyExtensionTable(
			'cw_wikis',
			"$dir/patches/patch-cw_wikis-update-smallint-to-tinyint.sql"
		);
	}
}
