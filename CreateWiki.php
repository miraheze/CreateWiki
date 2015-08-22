<?php
if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'Not an entry point.' );
}

$wgExtensionCredits['specialpage'][] = array(
	'author' => 'Southparkfan',
	'descriptionmsg' => 'createwiki-desc',
	'name' => 'CreateWiki',
	'path' => __FILE__,
	'url' => '//github.com/Miraheze/CreateWiki'
);

$wgAutoloadClasses['CreateWikiHooks'] = __DIR__ . '/CreateWiki.hooks.php';
$wgAutoloadClasses['CreateWikiLogFormatter'] = __DIR__ . '/CreateWikiLogFormatter.php';
$wgAutoloadClasses['RequestWikiQueuePager'] = __DIR__ . '/RequestWikiQueuePager.php';
$wgAutoloadClasses['SpecialCreateWiki'] = __DIR__ . '/SpecialCreateWiki.php';
$wgAutoloadClasses['SpecialRequestWiki'] = __DIR__ . '/SpecialRequestWiki.php';
$wgAutoloadClasses['SpecialRequestWikiQueue'] = __DIR__ . '/SpecialRequestWikiQueue.php';
$wgMessagesDirs['CreateWiki'] = __DIR__ . '/i18n';
$wgSpecialPages['CreateWiki'] = 'SpecialCreateWiki';
$wgSpecialPages['RequestWiki'] = 'SpecialRequestWiki';
$wgSpecialPages['RequestWikiQueue'] = 'SpecialRequestWikiQueue';

$wgHooks['LoadExtensionSchemaUpdates'][] = 'CreateWikiHooks::fnCreateWikiSchemaUpdates';

$wgAvailableRights[] = 'createwiki';
$wgLogTypes[] = 'farmer';
$wgLogActionsHandlers['farmer/createwiki'] = 'LogFormatter';
$wgLogActionsHandlers['farmer/requestwiki'] = 'CreateWikiLogFormatter';

$wgCreateWikiSQLfiles = array(
	"$IP/maintenance/tables.sql",
	"$IP/extensions/AbuseFilter/abusefilter.tables.sql",
	"$IP/extensions/AntiSpoof/sql/patch-antispoof.mysql.sql",
	"$IP/extensions/CheckUser/cu_log.sql",
	"$IP/extensions/CheckUser/cu_changes.sql"
);
