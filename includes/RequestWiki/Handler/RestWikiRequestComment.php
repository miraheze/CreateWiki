<?php

namespace Miraheze\CreateWiki\RequestWiki\Handler;

use Config;
use Exception;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Rest\SimpleHandler;
use Miraheze\CreateWiki\RequestWiki\WikiRequest;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\Rest\Validator\BodyValidator;
use MediaWiki\Rest\Validator\JsonBodyValidator;

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
		if ( $this->config->get( 'CreateWikiDisableRESTAPI' ) ) {
			return $this->getResponseFactory()->createLocalizedHttpError( 403, new MessageValue( 'createwiki-rest-disabled' ) );
		}
		// Should be kept in sync with RequestWikiRequestViewer's $visibilityConds
		$visibilityConds = [
			0 => 'public',
			1 => 'createwiki-deleterequest',
			2 => 'createwiki-suppressrequest',
		];
		try {
			$wikiRequest = new WikiRequest( $requestID );
		} catch ( Exception $e ) {
			return $this->getResponseFactory()->createLocalizedHttpError( 404, new MessageValue( 'requestwiki-unknown' ) );
		}
		// Must be logged in to use this API
		if ( !$this->getAuthority()->isNamed() ) {
			return $this->getResponseFactory()->createLocalizedHttpError( 403, new MessageValue( 'createwiki-rest-mustlogin' ) );
		}
		// Only allow users with (createwiki) the creator of the request to post comments
		if ( !$this->getAuthority()->isAllowed( 'createwiki' ) || $this->getAuthority()->getUser()->getId() !== $wikiRequest->requester->getId() ) {
			return $this->getResponseFactory()->createLocalizedHttpError( 403, new MessageValue( 'createwiki-rest-notallowed' ) );
		}
		// Do not allow blocked users to post comments
		if ( $this->getAuthority()->getBlock() ) {
			return $this->getResponseFactory()->createLocalizedHttpError( 403, new MessageValue( 'createwiki-rest-notallowed' ) );
		}

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

		$comment = $this->getValidatedBody()['comment'];
		$wikiRequest->addComment( $comment, $this->getAuthority()->getUser() );
	}

	public function needsWriteAccess() {
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

	public function getBodyValidator(): BodyValidator {
		return new JsonBodyValidator( [
			'comment' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRES => true,
			],
		] );
	}
}
