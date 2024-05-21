<?php

namespace Miraheze\CreateWiki\RequestWiki\Handler;

use Config;
use Exception;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\Validator\BodyValidator;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\UnsupportedContentTypeBodyValidator;
use Miraheze\CreateWiki\RequestWiki\WikiRequest;
use Miraheze\CreateWiki\RestUtils;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Posts a comment to the specified wiki request
 * POST /createwiki/v0/wiki_request/{id}/comment
 */
class RestWikiRequestComment extends SimpleHandler {

	/** @var Config */
	private $config;

	/**
	 * @param ConfigFactory $configFactory
	 */
	public function __construct(
		ConfigFactory $configFactory,
	) {
		$this->config = $configFactory->makeConfig( 'CreateWiki' );
	}

	public function run( $requestID ) {
		RestUtils::checkEnv();
		// Should be kept in sync with RequestWikiRequestViewer's $visibilityConds
		$visibilityConds = [
			0 => 'public',
			1 => 'createwiki-deleterequest',
			2 => 'createwiki-suppressrequest',
		];
		// Must be logged in to use this API
		if ( !$this->getAuthority()->isNamed() ) {
			return $this->getResponseFactory()->createLocalizedHttpError( 403, new MessageValue( 'createwiki-rest-mustlogin' ) );
		}
		try {
			$wikiRequest = new WikiRequest( $requestID );
		} catch ( Exception $e ) {
			return $this->getResponseFactory()->createLocalizedHttpError( 404, new MessageValue( 'requestwiki-unknown' ) );
		}
		// T12010: 3 is a legacy suppression level, treat is as a suppressed wiki request
		if ( $wikiRequest->getVisibility() >= 3 ) {
			return $this->getResponseFactory()->createLocalizedHttpError( 404, new MessageValue( 'requestwiki-unknown' ) );
		}
		$wikiRequestVisibility = $visibilityConds[$wikiRequest->getVisibility()];
		if ( $wikiRequestVisibility !== 'public' ) {
			if ( !$this->getAuthority()->isAllowed( $wikiRequestVisibility ) ) {
				// User does not have permission to view this request
				return $this->getResponseFactory()->createLocalizedHttpError( 404, new MessageValue( 'requestwiki-unknown' ) );
			}
		}
		// Only allow users with (createwiki) the creator of the request to post comments
		if ( !$this->getAuthority()->isAllowed( 'createwiki' ) || $this->getAuthority()->getUser()->getId() !== $wikiRequest->requester->getId() ) {
			return $this->getResponseFactory()->createLocalizedHttpError( 403, new MessageValue( 'createwiki-rest-notallowed' ) );
		}
		// Do not allow blocked users to post comments
		if ( $this->getAuthority()->getBlock() ) {
			return $this->getResponseFactory()->createLocalizedHttpError( 403, new MessageValue( 'createwiki-rest-notallowed' ) );
		}

		$comment = $this->getValidatedBody()['comment'];
		$commentWasPosted = $wikiRequest->addComment( $comment, $this->getAuthority()->getUser() );
		if ( !$commentWasPosted ) {
			return $this->getResponseFactory()->createLocalizedHttpError( 400, new MessageValue( 'createwiki-rest-emptycomment' ) );
		}
		return $this->getResponseFactory()->createNoContent();
	}

	public function needsWriteAccess() {
		return true;
	}

	public function requireSafeAgainstCsrf() {
		return true;
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
