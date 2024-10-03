<?php

namespace Miraheze\CreateWiki\RequestWiki\Handler;

use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\User\UserFactory;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\RestUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Returns the IDs and suppression level of all wiki requests made by a user
 * GET /createwiki/v0/wiki_requests/user/{username}
 */
class RestWikiRequestsByUser extends SimpleHandler {

	private Config $config;
	private UserFactory $userFactory;
	private WikiRequestManager $wikiRequestManager;

	public function __construct(
		ConfigFactory $configFactory,
		UserFactory $userFactory,
		WikiRequestManager $wikiRequestManager
	) {
		$this->config = $configFactory->makeConfig( 'CreateWiki' );
		$this->userFactory = $userFactory;
		$this->wikiRequestManager = $wikiRequestManager;
	}

	public function run( string $username ): Response {
		RestUtils::checkEnv( $this->config );

		$wikiRequests = $this->wikiRequestManager->getVisibleRequestsByUser(
			$this->userFactory->newFromName( $username ),
			$this->getAuthority()->getUser()
		);

		if ( $wikiRequests ) {
			return $this->getResponseFactory()->createJson( $wikiRequests );
		}

		// This user has never made wiki requests or the current
		// user can not view any of them.
		return $this->getResponseFactory()->createLocalizedHttpError(
			404, new MessageValue( 'createwiki-rest-usernowikirequests' )
		);
	}

	public function needsWriteAccess(): bool {
		return false;
	}

	public function getParamSettings(): array {
		return [
			'username' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}
