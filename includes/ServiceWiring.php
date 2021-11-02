<?php

use MediaWiki\MediaWikiServices;

return [
	'CreateWiki.NotificationsManager' => static function ( MediaWikiServices $services ): CreateWikiNotificationsManager {
		return new CreateWikiNotificationsManager(
			$services->getConfigFactory()->makeConfig( 'createwiki' ),
			$services->getUserFactory()
		);
	},
];
