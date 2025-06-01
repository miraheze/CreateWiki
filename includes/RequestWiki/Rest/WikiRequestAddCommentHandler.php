<?php

namespace Miraheze\CreateWiki\RequestWiki\Rest;

use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Miraheze\CreateWiki\Services\CreateWikiRestUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;
use function ctype_space;

/**
 * Posts a comment to the specified wiki request
 * POST /createwiki/v0/wiki_request/{id}/comment
 */
class WikiRequestAddCommentHandler extends SimpleHandler {

	public function __construct(
		private readonly CreateWikiRestUtils $restUtils,
		private readonly WikiRequestManager $wikiRequestManager
	) {
	}

	public function run( int $requestID ): Response {
		$this->restUtils->checkEnv();

		// Must be logged in to use this API
		if ( !$this->getAuthority()->isNamed() ) {
			return $this->getResponseFactory()->createLocalizedHttpError(
				403, new MessageValue( 'createwiki-rest-mustlogin' )
			);
		}

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

		$requester = $this->wikiRequestManager->getRequester();

		// Only allow users with (createwiki) or the creator of the request to post comments
		if (
			!$this->getAuthority()->isAllowed( 'createwiki' ) ||
			$this->getAuthority()->getUser()->getId() !== $requester->getId()
		) {
			return $this->getResponseFactory()->createLocalizedHttpError(
				403, new MessageValue( 'createwiki-rest-notallowed' )
			);
		}

		// Do not allow blocked users to post comments
		if ( $this->getAuthority()->getBlock() ) {
			return $this->getResponseFactory()->createLocalizedHttpError(
				403, new MessageValue( 'createwiki-rest-notallowed' )
			);
		}

		$validatedBody = $this->getValidatedBody();

		$comment = '';
		if ( $validatedBody ) {
			$comment = $validatedBody['comment'];
		}

		if ( !$comment || ctype_space( $comment ) ) {
			return $this->getResponseFactory()->createLocalizedHttpError(
				400, new MessageValue( 'createwiki-rest-emptycomment' )
			);
		}

		$this->wikiRequestManager->addComment(
			comment: $comment,
			user: $this->getAuthority()->getUser(),
			log: true,
			type: 'comment',
			// Use all involved users
			notifyUsers: []
		);

		return $this->getResponseFactory()->createNoContent();
	}

	public function needsWriteAccess(): bool {
		return true;
	}

	public function requireSafeAgainstCsrf(): bool {
		return true;
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

	/** @inheritDoc */
	public function getBodyParamSettings(): array {
		return [
			'comment' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}
