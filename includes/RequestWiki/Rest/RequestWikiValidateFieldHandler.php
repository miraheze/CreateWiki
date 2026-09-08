<?php

namespace Miraheze\CreateWiki\RequestWiki\Rest;

use MediaWiki\Message\Message;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\Validator;
use Miraheze\CreateWiki\Services\CreateWikiRestUtils;
use Miraheze\CreateWiki\Services\CreateWikiValidator;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Validates a single RequestWiki field ahead of full submission
 * POST /createwiki/v0/request_wiki/validate
 */
class RequestWikiValidateFieldHandler extends SimpleHandler {

	use TokenAwareHandlerTrait;

	public function __construct(
		private readonly CreateWikiRestUtils $restUtils,
		private readonly CreateWikiValidator $validator,
	) {
	}

	/** @inheritDoc */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	public function run(): Response {
		$this->restUtils->checkEnv();
		if ( !$this->getAuthority()->isNamed() ) {
			return $this->getResponseFactory()->createLocalizedHttpError(
				403, new MessageValue( 'createwiki-rest-mustlogin' )
			);
		}

		if ( $this->getAuthority()->getBlock() ) {
			return $this->getResponseFactory()->createLocalizedHttpError(
				403, new MessageValue( 'createwiki-rest-notallowed' )
			);
		}

		$body = $this->getValidatedBody();
		$value = $body['value'];

		$result = match ( $body['field'] ) {
			'agreement' => $this->validator->validateAgreement( $value === '1' ),
			'category', 'purpose' => $this->validator->validateRequired( $value ),
			'reason' => $this->validator->validateReason( $value, [] ),
			'subdomain' => $this->validator->validateSubdomain( $value, [] ),
			default => true,
		};

		if ( $result === true ) {
			return $this->getResponseFactory()->createJson( [ 'valid' => true ] );
		}

		return $this->getResponseFactory()->createJson( [
			'valid' => false,
			'message' => $result instanceof Message ? $result->parse() : (string)$result,
		] );
	}

	public function needsWriteAccess(): false {
		return false;
	}

	public function getBodyParamSettings(): array {
		return [
			'field' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => [
					'agreement',
					'category',
					'purpose',
					'reason',
					'subdomain',
				],
				ParamValidator::PARAM_REQUIRED => true,
			],
			'value' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		] + $this->getTokenParamDefinition();
	}
}
