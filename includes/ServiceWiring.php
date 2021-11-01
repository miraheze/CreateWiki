<?php

use MediaWiki\MediaWikiServices;

return [
	'CreateWiki.NotificationsManager' => static function ( MediaWikiServices $services ): NotificationsManager {
		return new NotificationsManager(
			$services->getConfigFactory()->makeConfig( 'createwiki' ),
			$services->getUserFactory()
		);
	},
];
