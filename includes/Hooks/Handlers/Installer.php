<?php

namespace Miraheze\CreateWiki\Hooks\Handlers;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class Installer implements LoadExtensionSchemaUpdatesHook {

	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$updater->addExtensionTable(
			'cw_requests',
			__DIR__ . '/../sql/cw_requests.sql'
		);

		$updater->addExtensionTable(
			'cw_comments',
			__DIR__ . '/../sql/cw_comments.sql'
		);

		$updater->modifyExtensionField(
			'cw_comments',
			'cw_comment_user',
			__DIR__ . '/../sql/patches/patch-cw_comments-int.sql'
		);

		$updater->modifyExtensionField(
			'cw_requests',
			'cw_user',
			__DIR__ . '/../sql/patches/patch-cw_requests-int.sql'
		);

		$updater->modifyExtensionField(
			'cw_comments',
			'cw_comment_user',
			__DIR__ . '/../sql/patches/patch-cw_comments-blob.sql'
		);

		$updater->addExtensionField(
			'cw_requests',
			'cw_bio',
			__DIR__ . '/../sql/patches/patch-cw_requests-add-cw_bio.sql'
		);

		$updater->addExtensionField(
			'cw_wikis',
			'wiki_inactive_exempt_reason',
			__DIR__ . '/../sql/patches/patch-cw_wikis-add-wiki_inactive_exempt_reason.sql'
		);

		$updater->addExtensionTable(
			'cw_wikis',
			__DIR__ . '/../sql/cw_wikis.sql'
		);

		$updater->addExtensionField(
			'cw_wikis',
			'wiki_deleted',
			__DIR__ . '/../sql/patches/patch-deleted-wiki.sql'
		);

		$updater->addExtensionField(
			'cw_wikis',
			'wiki_deleted_timestamp',
			__DIR__ . '/../sql/patches/patch-deleted-wiki.sql'
		);

		$updater->addExtensionField(
			'cw_wikis',
			'wiki_inactive_exempt',
			__DIR__ . '/../sql/patches/patch-inactive-exempt.sql'
		);

		$updater->addExtensionField(
			'cw_wikis',
			'wiki_locked',
			__DIR__ . '/../sql/patches/patch-locked-wiki.sql'
		);

		$updater->addExtensionField(
			'cw_wikis',
			'wiki_url',
			__DIR__ . '/../sql/patches/patch-domain-cols.sql'
		);

		$updater->addExtensionIndex(
			'cw_wikis',
			'wiki_dbname',
			__DIR__ . '/../sql/patches/patch-cw_wikis-add-indexes.sql'
		);

		$updater->addExtensionField(
			'cw_wikis',
			'wiki_experimental',
			__DIR__ . '/../sql/patches/patch-cw_wikis-add-wiki_experimental.sql'
		);

		$updater->modifyExtensionField(
			'cw_wikis',
			'wiki_closed',
			__DIR__ . '/../sql/patches/patch-cw_wikis-add-default-to-wiki_closed.sql'
		);

		$updater->modifyExtensionField(
			'cw_wikis',
			'wiki_deleted',
			__DIR__ . '/../sql/patches/patch-cw_wikis-add-default-to-wiki_deleted.sql'
		);

		$updater->modifyExtensionField(
			'cw_wikis',
			'wiki_inactive',
			__DIR__ . '/../sql/patches/patch-cw_wikis-add-default-to-wiki_inactive.sql'
		);

		$updater->modifyExtensionField(
			'cw_wikis',
			'wiki_inactive_exempt',
			__DIR__ . '/../sql/patches/patch-cw_wikis-add-default-to-wiki_inactive_exempt.sql'
		);

		$updater->modifyExtensionField(
			'cw_wikis',
			'wiki_locked',
			__DIR__ . '/../sql/patches/patch-cw_wikis-add-default-to-wiki_locked.sql'
		);
	}
}
