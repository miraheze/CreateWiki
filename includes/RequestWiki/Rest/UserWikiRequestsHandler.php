<?php

namespace Miraheze\CreateWiki\RequestWiki\Rest;

use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\User\UserFactory;
use Miraheze\CreateWiki\Services\CreateWikiRestUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Returns the IDs and suppression level of all wiki requests made by a user
 * GET /createwiki/v0/wiki_requests/user/{username}
 */
class UserWikiRequestsHandler extends SimpleHandler {

	public function __construct(
		private readonly CreateWikiRestUtils $restUtils,
		private readonly UserFactory $userFactory,
		private readonly WikiRequestManager $wikiRequestManager
	) {
	}

	public function run( string $username ): Response {
		$this->restUtils->checkEnv();

		$requester = $this->userFactory->newFromName( $username );

		if ( !$requester ) {
			// A non-existing user has no requests
			return $this->getResponseFactory()->createLocalizedHttpError(
				404, new MessageValue( 'createwiki-rest-usernowikirequests' )
			);
		}

		$wikiRequests = $this->wikiRequestManager->getVisibleRequestsByUser(
			$requester, $this->getAuthority()->getUser()
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
