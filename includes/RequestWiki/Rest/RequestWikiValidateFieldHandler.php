<?php

namespace Miraheze\CreateWiki\RequestWiki\Rest;

use MediaWiki\Context\RequestContext;
use MediaWiki\Message\Message;
use MediaWiki\ParamValidator\TypeDef\ArrayDef;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\SpecialPage\SpecialPageFactory;
use Miraheze\CreateWiki\RequestWiki\Specials\SpecialRequestWiki;
use Miraheze\CreateWiki\Services\CreateWikiRestUtils;
use Miraheze\CreateWiki\Services\CreateWikiValidator;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;
use function is_array;

/**
 * Validates a batch of RequestWiki fields ahead of full submission
 * POST /createwiki/v0/request_wiki/validate
 */
class RequestWikiValidateFieldHandler extends SimpleHandler {

	use TokenAwareHandlerTrait;

	public function __construct(
		private readonly CreateWikiRestUtils $restUtils,
		private readonly CreateWikiValidator $validator,
		private readonly SpecialPageFactory $specialPageFactory,
	) {
	}

	/** @inheritDoc */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		// $this->validateToken();
	}

	public function run(): Response {
		$this->restUtils->checkEnv();
		/* if ( !$this->getAuthority()->isNamed() ) {
			return $this->getResponseFactory()->createLocalizedHttpError(
				403, new MessageValue( 'createwiki-rest-mustlogin' )
			);
		}

		if ( $this->getAuthority()->getBlock() ) {
			return $this->getResponseFactory()->createLocalizedHttpError(
				403, new MessageValue( 'createwiki-rest-notallowed' )
			);
		} */

		$validatedBody = $this->getValidatedBody();

		$checks = [];
		if ( $validatedBody && is_array( $validatedBody['checks'] ) ) {
			$checks = $validatedBody['checks'];
		}

		$specialPage = $this->specialPageFactory->getPage( 'RequestWiki' );
		if ( $specialPage instanceof SpecialRequestWiki ) {
			$specialPage->setContext( RequestContext::getMain() );
		} else {
			$specialPage = null;
		}

		$results = [];
		foreach ( $checks as $check ) {
			if ( !is_array( $check ) || !isset( $check['field'] ) || !isset( $check['value'] ) ) {
				continue;
			}

			$field = (string)$check['field'];
			$value = (string)$check['value'];

			if ( $specialPage === null ) {
				$results[$field] = [ 'valid' => true ];
				continue;
			}

			/* if ( $field === 'ratelimited' ) {
				if ( $specialPage->getUser()->pingLimiter( 'requestwiki', 0 ) ) {
					return $this->getResponseFactory()->createLocalizedHttpError(
						429, new MessageValue( 'actionthrottledtext' )
					);
				}

				continue;
			}

			if ( $field === 'duplicate' ) {
				if ( $specialPage->isDuplicateRequest( $value ) ) {
					return $this->getResponseFactory()->createLocalizedHttpError(
						403, new MessageValue( 'requestwiki-error-patient' )
					);
				}

				continue;
			} */

			$result = $this->validateField( $specialPage, $field, $value );
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
		SpecialRequestWiki $specialPage,
		string $field,
		string $value
	): Message|true {
		$info = $specialPage->getRestValidationInfo( $field );
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
