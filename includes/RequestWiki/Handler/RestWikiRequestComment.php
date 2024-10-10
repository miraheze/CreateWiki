<?php

namespace Miraheze\CreateWiki\RequestWiki\Handler;

use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\Validator\BodyValidator;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\UnsupportedContentTypeBodyValidator;
use Miraheze\CreateWiki\RestUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Posts a comment to the specified wiki request
 * POST /createwiki/v0/wiki_request/{id}/comment
 */
class RestWikiRequestComment extends SimpleHandler {

	private Config $config;
	private WikiRequestManager $wikiRequestManager;

	public function __construct(
		ConfigFactory $configFactory,
		WikiRequestManager $wikiRequestManager
	) {
		$this->config = $configFactory->makeConfig( 'CreateWiki' );
		$this->wikiRequestManager = $wikiRequestManager;
	}

	public function run( int $requestID ): Response {
		RestUtils::checkEnv( $this->config );

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
	public function getBodyValidator( $contentType ): BodyValidator {
		if ( $contentType !== 'application/json' ) {
			return new UnsupportedContentTypeBodyValidator( $contentType );
		}

		return new JsonBodyValidator( [
			'comment' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		] );
	}
}
