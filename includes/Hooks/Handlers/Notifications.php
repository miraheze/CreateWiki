<?php

namespace Miraheze\CreateWiki\Hooks\Handlers;

use MediaWiki\Extension\Notifications\AttributeManager;
use MediaWiki\Extension\Notifications\Hooks\BeforeCreateEchoEventHook;
use MediaWiki\Extension\Notifications\UserLocator;
use Miraheze\CreateWiki\Notifications\EchoCreateWikiPresentationModel;
use Miraheze\CreateWiki\Notifications\EchoRequestCommentPresentationModel;
use Miraheze\CreateWiki\Notifications\EchoRequestDeclinedPresentationModel;
use Miraheze\CreateWiki\Notifications\EchoRequestMoreDetailsPresentationModel;

class Notifications implements BeforeCreateEchoEventHook {

	/**
	 * Add CreateWiki events to Echo
	 *
	 * @param array &$notifications array of Echo notifications
	 * @param array &$notificationCategories array of Echo notification categories
	 * @param array &$icons array of icon details
	 */
	public function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$icons
	): void {
		$notificationCategories['wiki-creation'] = [
			'priority' => 3,
			'no-dismiss' => [ 'all' ]
		];

		$notificationCategories['request-declined'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-wiki-request-declined',
			'no-dismiss' => [ 'email' ]
		];

		$notificationCategories['request-moredetails'] = [
			'priority' => 1,
			'no-dismiss' => [ 'all' ]
		];

		$notificationCategories['request-comment'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-wiki-request-comment'
		];

		$notifications['wiki-creation'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'wiki-creation',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoCreateWikiPresentationModel::class,
			'immediate' => true
		];

		$notifications['request-declined'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'request-declined',
			'group' => 'negative',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestDeclinedPresentationModel::class,
			'immediate' => true
		];

		$notifications['request-moredetails'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'request-moredetails',
			'group' => 'interactive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestMoreDetailsPresentationModel::class,
			'immediate' => true
		];

		$notifications['request-comment'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'request-comment',
			'group' => 'interactive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestCommentPresentationModel::class,
			'immediate' => true
		];

		$icons['request-declined'] = [
			'path' => 'CreateWiki/modules/icons/decline.svg'
		];
	}
}
