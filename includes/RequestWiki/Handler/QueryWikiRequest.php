<?php

namespace Miraheze\CreateWiki\RequestWiki\Handler;

use Config;
use MediaWiki\User\UserFactory;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\ILBFactory;

/**
 * Returns all information related to a wiki request
 * Only publicly accessible wiki requests can be queried through this API
 * GET /createwiki/v0/query_wiki_request/{id}
 */
class QueryWikiRequest  extends SimpleHandler {

	/** @var Config */
	private $config;

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

	/** @var UserFactory */
	private $userFactory;

	public function __construct(
		Config $config,
		ILBFactory $dbLoadBalancerFactory,
		UserFactory $userFactory
	) {
		$this->config = $config
		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
		$this->userFactory = $userFactory
	}

	public function run( $id ) {
		$requestID = (int)$id;
		$dbr = $this->dbLoadBalancerFactory->getMainLB( $this->config->get( 'CreateWikiGlobalWiki' ) )
			->getMaintenanceConnectionRef( DB_REPLICA, [], $this->config->get( 'CreateWikiGlobalWiki' ) );
		$wikiRequest = $dbr->selectRow(
			'cw_requests'
			'*',
			[
				'cw_visibility' => 0,
				'cw_id' => $requestID,
			],
			__METHOD__
		)
		if ( $wikiRequest ) {
			$response = [
				'comment' => $wikiRequest->cw_comment,
				'dbname' => $wikiRequest->cw_dbname,
				'language' => $wikiRequest->cw_language,
				'sitename' => $wikiRequest->cw_sitename,
				'status' => $wikiRequest->cw_status,
				'timestamp' => $wikiRequest->cw_timestamp,
				'url' => $wikiRequest->cw_url,
				'requester' => $this->userFactory->newFromId( $wikiRequest->cw_user )->getName,
				'category' => $wikiRequest->cw_category,
				'bio' => $wikiRequest->cw_bio,
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
			)
			$wikiRequestComments = [];
			foreach ( $wikiRequestCwComments as $comment ) {
				$wikiRequestComments[] = [
					'comment' => $wikiRequestCwComments->cw_comment,
					'timestamp' => $wikiRequestCwComments->cw_comment_timestamp,
					'user' => $this->userFactory->newFromId( $wikiRequestCwComments->cw_comment_user )->getName(),
				];
			}

			// We now have all the data we need, add the comments to $response and return

			$response['comments'] = $wikiRequestComments;
			return $response;
		}
	}

	public function needsWriteAccess() {
		return false;
	}

	public function getParamSettings() {
		return [
			'id' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}
