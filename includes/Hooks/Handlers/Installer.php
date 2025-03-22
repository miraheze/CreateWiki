<?php

namespace Miraheze\CreateWiki\Hooks\Handlers;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use Miraheze\CreateWiki\Maintenance\PopulateCentralWiki;

class Installer implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @inheritDoc
	 * @codeCoverageIgnore Tested by updating or installing MediaWiki.
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = __DIR__ . '/../../../sql';

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-createwiki-central',
			'addTable',
			'cw_requests',
			"$dir/cw_requests.sql",
			true,
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-createwiki-central',
			'addTable',
			'cw_comments',
			"$dir/cw_comments.sql",
			true,
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-createwiki-central',
			'addTable',
			'cw_history',
			"$dir/cw_history.sql",
			true,
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-createwiki',
			'addTable',
			'cw_wikis',
			"$dir/cw_wikis.sql",
			true,
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-createwiki-central',
			'addField',
			'cw_requests',
			'cw_locked',
			"$dir/patches/patch-cw_requests-add-cw_locked.sql",
			true,
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-createwiki-central',
			'addField',
			'cw_requests',
			'cw_extra',
			"$dir/patches/patch-cw_requests-add-cw_extra.sql",
			true,
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-createwiki',
			'addField',
			'cw_wikis',
			'wiki_extra',
			"$dir/patches/patch-cw_wikis-add-wiki_extra.sql",
			true,
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-createwiki',
			'modifyTable',
			'cw_wikis',
			"$dir/patches/patch-cw_wikis-update-smallint-to-tinyint.sql",
			true,
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-createwiki-central',
			'modifyField',
			'cw_requests',
			'cw_private',
			"$dir/patches/patch-cw_requests-modify-cw_private.sql",
			true,
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-createwiki-central',
			'modifyField',
			'cw_requests',
			'cw_timestamp',
			"$dir/patches/patch-cw_requests-modify-cw_timestamp.sql",
			true,
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-createwiki-central',
			'modifyField',
			'cw_comments',
			'cw_comment_timestamp',
			"$dir/patches/patch-cw_comments-modify-cw_comment_timestamp.sql",
			true,
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-createwiki',
			'modifyField',
			'cw_wikis',
			'wiki_creation',
			"$dir/patches/patch-cw_wikis-modify-wiki_creation-default.sql",
			true,
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-createwiki',
			'dropField',
			'cw_wikis',
			'wiki_extensions',
			"$dir/patches/patch-cw_wikis-drop-wiki_extensions.sql",
			true,
		] );

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-createwiki',
			'dropField',
			'cw_wikis',
			'wiki_settings',
			"$dir/patches/patch-cw_wikis-drop-wiki_settings.sql",
			true,
		] );

		$updater->addPostDatabaseUpdateMaintenance( PopulateCentralWiki::class );
	}
}
