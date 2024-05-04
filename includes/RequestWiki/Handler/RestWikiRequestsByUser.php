<?php

namespace Miraheze\CreateWiki\RequestWiki\Handler;

use Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\User\UserFactory;
use Miraheze\CreateWiki\RestUtils;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\ILBFactory;

/**
 * Returns the IDs and suppression level of all wiki requests made by an user
 * GET /createwiki/v0/wiki_requests/user/{username}
 */
class RestWikiRequestsByUser extends SimpleHandler {

	/** @var Config */
	private $config;

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param ConfigFactory $configFactory
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		ConfigFactory $configFactory,
		ILBFactory $dbLoadBalancerFactory,
		UserFactory $userFactory
	) {
		$this->config = $configFactory->makeConfig( 'CreateWiki' );
		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
		$this->userFactory = $userFactory;
	}

	public function run( $userName ) {
		RestUtils::checkEnv();
		// Should be kept in sync with RequestWikiRequestViewer's $visibilityConds
		$visibilityConds = [
			0 => 'public',
			1 => 'createwiki-deleterequest',
			2 => 'createwiki-suppressrequest',
		];
		$dbr = $this->dbLoadBalancerFactory->getMainLB( $this->config->get( 'CreateWikiGlobalWiki' ) )
			->getMaintenanceConnectionRef( DB_REPLICA, [], $this->config->get( 'CreateWikiGlobalWiki' ) );
		$wikiRequestsArray = [];
		$userID = $this->userFactory->newFromName( $userName )->getId();
		$wikiRequests = $dbr->select(
			'cw_requests',
			[
				'cw_id',
				'cw_visibility',
			],
			[
				'cw_user' => $userID,
			],
			__METHOD__
		);
		if ( $wikiRequests ) {
			foreach ( $wikiRequests as $wikiRequest ) {
				// T12010: 3 is a legacy suppression level, treat is as a suppressed wiki request
				if ( $wikiRequest->cw_visibility >= 3 ) {
					continue;
				}

				$wikiRequestVisibility = $visibilityConds[$wikiRequest->cw_visibility];

				if ( $wikiRequestVisibility !== 'public' ) {
					if ( !$this->getAuthority()->isAllowed( $wikiRequestVisibility ) ) {
						// User does not have permission to view this request
						continue;
					}
				}

				$wikiRequestsArray[] = ['id' => (int)$wikiRequest->cw_id, 'visibility' => $wikiRequestVisibility];
			}

			if ( count( $wikiRequestsArray ) === 0 ) {
				// This user _has_ made wiki requests, but these are suppressed wiki requests and the user making this request doesn't have permission to view them
				return $this->getResponseFactory()->createLocalizedHttpError( 404, new MessageValue( 'createwiki-rest-usernowikirequests' ) );
			}
			return $this->getResponseFactory()->createJson( $wikiRequestsArray );
		}
		// This user has never made a wiki request
		return $this->getResponseFactory()->createLocalizedHttpError( 404, new MessageValue( 'createwiki-rest-usernowikirequests' ) );
	}

	public function needsWriteAccess() {
		return false;
	}

	public function getParamSettings() {
		return [
			'username' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}
