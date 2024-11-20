<?php

namespace Miraheze\CreateWiki\RequestWiki\Handler;

use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\User\UserFactory;
use Miraheze\CreateWiki\Services\CreateWikiRestUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Returns information related to a wiki request
 * GET /createwiki/v0/wiki_request/{id}
 */
class RestWikiRequest extends SimpleHandler {

	private IConnectionProvider $connectionProvider;
	private CreateWikiRestUtils $restUtils;
	private UserFactory $userFactory;

	public function __construct(
		IConnectionProvider $connectionProvider,
		CreateWikiRestUtils $restUtils,
		UserFactory $userFactory
	) {
		$this->connectionProvider = $connectionProvider;
		$this->restUtils = $restUtils;
		$this->userFactory = $userFactory;
	}

	public function run( int $requestID ): Response {
		$this->restUtils->checkEnv();

		$visibilityConds = WikiRequestManager::VISIBILITY_CONDS;

		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-createwiki-central' );

		$wikiRequest = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'cw_requests' )
			->where( [ 'cw_id' => $requestID ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $wikiRequest ) {
			// T12010: 3 is a legacy suppression level, treat is as a suppressed wiki request
			if ( $wikiRequest->cw_visibility >= 3 ) {
				return $this->getResponseFactory()->createLocalizedHttpError(
					404, new MessageValue( 'requestwiki-unknown' )
				);
			}

			$wikiRequestVisibility = $visibilityConds[$wikiRequest->cw_visibility];

			if ( $wikiRequestVisibility !== 'public' ) {
				if ( !$this->getAuthority()->isAllowed( $wikiRequestVisibility ) ) {
					// User does not have permission to view this request
					return $this->getResponseFactory()->createLocalizedHttpError(
						404, new MessageValue( 'requestwiki-unknown' )
					);
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
				'bio' => (bool)$wikiRequest->cw_bio,
				'visibility' => $wikiRequestVisibility,
			];

			$wikiRequestCwComments = $dbr->newSelectQueryBuilder()
				->select( '*' )
				->from( 'cw_comments' )
				->where( [ 'cw_id' => $requestID ] )
				->orderBy( 'cw_comment_timestamp', SelectQueryBuilder::SORT_DESC )
				->caller( __METHOD__ )
				->fetchResultSet();

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

	public function needsWriteAccess(): bool {
		return false;
	}

	public function getParamSettings(): array {
		return [
			'id' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}
