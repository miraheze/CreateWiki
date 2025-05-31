<?php

namespace Miraheze\CreateWiki\RequestWiki\Rest;

use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Miraheze\CreateWiki\Services\CreateWikiRestUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;
use function wfTimestamp;
use const TS_ISO_8601;

/**
 * Returns information related to a wiki request
 * GET /createwiki/v0/wiki_request/{id}
 */
class WikiRequestInfoHandler extends SimpleHandler {

	public function __construct(
		private readonly CreateWikiRestUtils $restUtils,
		private readonly WikiRequestManager $wikiRequestManager
	) {
	}

	public function run( int $requestID ): Response {
		$this->restUtils->checkEnv();

		$this->wikiRequestManager->loadFromID( $requestID );

		if ( !$this->wikiRequestManager->exists() ) {
			return $this->getResponseFactory()->createLocalizedHttpError(
				404, new MessageValue( 'requestwiki-unknown' )
			);
		}

		if ( !$this->wikiRequestManager->isVisibilityAllowed(
			$this->wikiRequestManager->getVisibility(),
			$this->getAuthority()->getUser()
		) ) {
			return $this->getResponseFactory()->createLocalizedHttpError(
				404, new MessageValue( 'requestwiki-unknown' )
			);
		}

		$response = [
			'reason' => $this->wikiRequestManager->getReason(),
			'purpose' => $this->wikiRequestManager->getPurpose(),
			'dbname' => $this->wikiRequestManager->getDBname(),
			'language' => $this->wikiRequestManager->getLanguage(),
			'sitename' => $this->wikiRequestManager->getSitename(),
			'status' => $this->wikiRequestManager->getStatus(),
			'timestamp' => wfTimestamp( TS_ISO_8601, $this->wikiRequestManager->getTimestamp() ),
			'url' => $this->wikiRequestManager->getUrl(),
			'requester' => $this->wikiRequestManager->getRequester()->getName(),
			'category' => $this->wikiRequestManager->getCategory(),
			'bio' => $this->wikiRequestManager->isBio(),
			'visibility' => $this->wikiRequestManager->getVisibility(),
		];

		$formattedComments = [];
		foreach ( $this->wikiRequestManager->getComments() as $comment ) {
			$formattedComments[] = [
				'comment' => $comment['comment'],
				'timestamp' => wfTimestamp( TS_ISO_8601, $comment['timestamp'] ),
				'user' => $comment['user']->getName(),
			];
		}

		// We now have all the data we need, add the comments to $response and return
		$response['comments'] = $formattedComments;
		return $this->getResponseFactory()->createJson( $response );
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
