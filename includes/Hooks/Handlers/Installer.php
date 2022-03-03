<?php

namespace Miraheze\CreateWiki\Hooks\Handlers;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class Installer implements LoadExtensionSchemaUpdatesHook {

	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = __DIR__ . "/../../../sql";

		$updater->addExtensionTable(
			'cw_requests',
			"$dir/cw_requests.sql"
		);

		$updater->addExtensionTable(
			'cw_comments',
			"$dir/cw_comments.sql"
		);

		$updater->modifyExtensionField(
			'cw_comments',
			'cw_comment_user',
			"$dir/patches/patch-cw_comments-int.sql"
		);

		$updater->modifyExtensionField(
			'cw_requests',
			'cw_user',
			"$dir/patches/patch-cw_requests-int.sql"
		);

		$updater->modifyExtensionField(
			'cw_comments',
			'cw_comment_user',
			"$dir/patches/patch-cw_comments-blob.sql"
		);

		$updater->addExtensionField(
			'cw_requests',
			'cw_bio',
			"$dir/patches/patch-cw_requests-add-cw_bio.sql"
		);

		$updater->addExtensionField(
			'cw_wikis',
			'wiki_inactive_exempt_reason',
			"$dir/patches/patch-cw_wikis-add-wiki_inactive_exempt_reason.sql"
		);

		$updater->addExtensionTable(
			'cw_wikis',
			"$dir/cw_wikis.sql"
		);

		$updater->addExtensionField(
			'cw_wikis',
			'wiki_deleted',
			"$dir/patches/patch-deleted-wiki.sql"
		);

		$updater->addExtensionField(
			'cw_wikis',
			'wiki_deleted_timestamp',
			"$dir/patches/patch-deleted-wiki.sql"
		);

		$updater->addExtensionField(
			'cw_wikis',
			'wiki_inactive_exempt',
			"$dir/patches/patch-inactive-exempt.sql"
		);

		$updater->addExtensionField(
			'cw_wikis',
			'wiki_locked',
			"$dir/patches/patch-locked-wiki.sql"
		);

		$updater->addExtensionField(
			'cw_wikis',
			'wiki_url',
			"$dir/patches/patch-domain-cols.sql"
		);

		$updater->addExtensionIndex(
			'cw_wikis',
			'wiki_dbname',
			"$dir/patches/patch-cw_wikis-add-indexes.sql"
		);

		$updater->addExtensionField(
			'cw_wikis',
			'wiki_experimental',
			"$dir/patches/patch-cw_wikis-add-wiki_experimental.sql"
		);

		$updater->modifyExtensionField(
			'cw_wikis',
			'wiki_closed',
			"$dir/patches/patch-cw_wikis-add-default-to-wiki_closed.sql"
		);

		$updater->modifyExtensionField(
			'cw_wikis',
			'wiki_deleted',
			"$dir/patches/patch-cw_wikis-add-default-to-wiki_deleted.sql"
		);

		$updater->modifyExtensionField(
			'cw_wikis',
			'wiki_inactive',
			"$dir/patches/patch-cw_wikis-add-default-to-wiki_inactive.sql"
		);

		$updater->modifyExtensionField(
			'cw_wikis',
			'wiki_inactive_exempt',
			"$dir/patches/patch-cw_wikis-add-default-to-wiki_inactive_exempt.sql"
		);

		$updater->modifyExtensionField(
			'cw_wikis',
			'wiki_locked',
			"$dir/patches/patch-cw_wikis-add-default-to-wiki_locked.sql"
		);
	}
}
