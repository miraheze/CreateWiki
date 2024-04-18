<?php

namespace Miraheze\CreateWiki\RequestWiki\Handler;

use Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\User\UserFactory;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\ILBFactory;

/**
 * Returns information related to a wiki request
 * GET /createwiki/v0/wiki_request/{id}
 */
class RestWikiRequest extends SimpleHandler {

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

	public function run( $requestID ) {
		// Should be kept in sync with RequestWikiRequestViewer's $visibilityConds
		$visibilityConds = [
			0 => 'public',
			1 => 'createwiki-deleterequest',
			2 => 'createwiki-suppressrequest',
		];
		$dbr = $this->dbLoadBalancerFactory->getMainLB( $this->config->get( 'CreateWikiGlobalWiki' ) )
			->getMaintenanceConnectionRef( DB_REPLICA, [], $this->config->get( 'CreateWikiGlobalWiki' ) );
		$wikiRequest = $dbr->selectRow(
			'cw_requests',
			'*',
			[
				'cw_id' => $requestID,
			],
			__METHOD__
		);
		if ( $wikiRequest ) {
			// T12010: 3 is a legacy suppression level, treat is as a suppressed wiki request
			if ( $wikiRequest->cw_visibility >= 3 ) {
				return $this->getResponseFactory()->createLocalizedHttpError( 404, new MessageValue( 'requestwiki-unknown' ) );
			}

			$wikiRequestVisibility = $visibilityConds[$wikiRequest->cw_visibility];

			if ( $wikiRequestVisibility !== 'public' ) {
				if ( !$this->getAuthority()->isAllowed( $wikiRequestVisibility ) ) {
					// User does not have permission to view this request
					return $this->getResponseFactory()->createLocalizedHttpError( 404, new MessageValue( 'requestwiki-unknown' ) );
				}
			}

			$response = [
				'comment' => $wikiRequest->cw_comment,
				'dbname' => $wikiRequest->cw_dbname,
				'language' => $wikiRequest->cw_language,
				'sitename' => $wikiRequest->cw_sitename,
				'status' => $wikiRequest->cw_status,
				'timestamp' => wfTimestamp( TS_ISO_8601, $wikiRequest->cw_timestamp ),
				'url' => $wikiRequest->cw_url,
				'requester' => $this->userFactory->newFromId( $wikiRequest->cw_user )->getName(),
				'category' => $wikiRequest->cw_category,
				'bio' => $wikiRequest->cw_bio,
				'visibility' => $wikiRequestVisibility,
			];
			$wikiRequestCwComments = $dbr->select(
				'cw_comments',
				'*',
				[
					'cw_id' => $requestID,
				],
				__METHOD__,
				[
					'cw_comment_timestamp DESC',
				]
			);
			$wikiRequestComments = [];
			foreach ( $wikiRequestCwComments as $comment ) {
				$wikiRequestComments[] = [
					'comment' => $comment->cw_comment,
					'timestamp' => wfTimestamp( TS_ISO_8601, $comment->cw_comment_timestamp ),
					'user' => $this->userFactory->newFromId( $comment->cw_comment_user )->getName(),
				];
			}
			// We now have all the data we need, add the comments to $response and return
			$response['comments'] = $wikiRequestComments;
			return $this->getResponseFactory()->createJson( $response );
		}
		// Request does not exist
		return $this->getResponseFactory()->createLocalizedHttpError( 404, new MessageValue( 'requestwiki-unknown' ) );
	}

	public function needsWriteAccess() {
		return false;
	}

	public function getParamSettings() {
		return [
			'id' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}
