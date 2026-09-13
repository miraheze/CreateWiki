<?php

namespace Miraheze\CreateWiki\RequestWiki\Rest;

use MediaWiki\Message\Message;
use MediaWiki\ParamValidator\TypeDef\ArrayDef;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\User\UserFactory;
use Miraheze\CreateWiki\RequestWiki\RequestWikiFormDescriptorBuilder;
use Miraheze\CreateWiki\Services\CreateWikiRestUtils;
use Miraheze\CreateWiki\Services\CreateWikiValidator;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;
use function is_array;

/**
 * Validates a batch of RequestWiki fields ahead of full submission
 * POST /createwiki/v0/request_wiki/validate
 */
class RequestWikiValidateFieldsHandler extends SimpleHandler {

	use TokenAwareHandlerTrait;

	public function __construct(
		private readonly CreateWikiRestUtils $restUtils,
		private readonly CreateWikiValidator $validator,
		private readonly RequestWikiFormDescriptorBuilder $formDescriptorBuilder,
		private readonly UserFactory $userFactory,
		private readonly WikiRequestManager $wikiRequestManager,
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

		$validatedBody = $this->getValidatedBody();

		$checks = [];
		if ( $validatedBody && is_array( $validatedBody['checks'] ) ) {
			$checks = $validatedBody['checks'];
		}

		$formDescriptor = $this->formDescriptorBuilder->build()['descriptor'];

		$results = [];
		foreach ( $checks as $check ) {
			if ( !is_array( $check ) || !isset( $check['field'] ) || !isset( $check['value'] ) ) {
				continue;
			}

			$field = (string)$check['field'];
			$value = (string)$check['value'];

			if ( $field === 'throttled' ) {
				$user = $this->userFactory->newFromAuthority( $this->getAuthority() );
				if ( $user->pingLimiter( 'requestwiki', 0 ) ) {
					return $this->getResponseFactory()->createLocalizedHttpError(
						429, new MessageValue( 'requestwiki-throttled' )
					);
				}

				continue;
			}

			if ( $field === 'duplicate' ) {
				if ( $this->wikiRequestManager->isDuplicateRequest( $value ) ) {
					return $this->getResponseFactory()->createLocalizedHttpError(
						403, new MessageValue( 'requestwiki-error-patient' )
					);
				}

				continue;
			}

			$result = $this->validateField( $formDescriptor, $field, $value );
			$results[$field] = $result === true
				? [ 'valid' => true ]
				: [
					'valid' => false,
					'message' => $result instanceof Message ? $result->parse() : (string)$result,
				];
		}

		return $this->getResponseFactory()->createJson( [ 'results' => $results ] );
	}

	private function validateField(
		array $formDescriptor,
		string $field,
		string $value
	): Message|true {
		$info = $this->formDescriptorBuilder->getRestValidationInfo( $formDescriptor, $field );
		if ( $info === null ) {
			return true;
		}

		if ( $info['callback'] !== null ) {
			$callbackValue = $info['type'] === 'check' ? $value === '1' : $value;
			return ( $info['callback'] )( $callbackValue, [] );
		}

		if ( $info['required'] ) {
			return $this->validator->validateRequired( $value );
		}

		return true;
	}

	public function needsWriteAccess(): false {
		return false;
	}

	public function getBodyParamSettings(): array {
		return [
			'checks' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'array',
				ParamValidator::PARAM_REQUIRED => true,
				self::PARAM_DESCRIPTION => new MessageValue( 'createwiki-rest-checks-description' ),
				ArrayDef::PARAM_SCHEMA => ArrayDef::makeListSchema(
					ArrayDef::makeObjectSchema( [
						'field' => [ 'type' => 'string', 'example' => 'subdomain' ],
						'value' => [ 'type' => 'string', 'example' => 'mywiki' ],
					] )
				),
			],
		] + $this->getTokenParamDefinition();
	}
}
