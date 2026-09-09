<?php

namespace Miraheze\CreateWiki\RequestWiki\Rest;

use MediaWiki\Context\RequestContext;
use MediaWiki\Message\Message;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\User\UserFactory;
use Miraheze\CreateWiki\RequestWiki\Specials\SpecialRequestWiki;
use Miraheze\CreateWiki\Services\CreateWikiRestUtils;
use Miraheze\CreateWiki\Services\CreateWikiValidator;
use Miraheze\CreateWiki\Services\WikiRequestManager;
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
		private readonly SpecialPageFactory $specialPageFactory,
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

		$field = '';
		$value = '';
		if ( $validatedBody ) {
			$field = $validatedBody['field'];
			$value = $validatedBody['value'];
		}

		$result = $this->validateField( $field, $value );
		if ( $result === true ) {
			return $this->getResponseFactory()->createJson( [ 'valid' => true ] );
		}

		return $this->getResponseFactory()->createJson( [
			'valid' => false,
			'message' => $result instanceof Message ? $result->parse() : (string)$result,
		] );
	}

	private function validateField( string $field, string $value ): Message|true {
		if ( $field === 'pinglimiter' ) {
			$user = $this->userFactory->newFromAuthority( $this->getAuthority() );
			return $this->validator->validatePingLimiter( $user );
		}

		if ( $field === 'duplicate' ) {
			$isDuplicate = $this->wikiRequestManager->isDuplicateRequest( $value );
			return $this->validator->validateDuplicateRequest( $isDuplicate );
		}

		$specialPage = $this->specialPageFactory->getPage( 'RequestWiki' );
		if ( !$specialPage instanceof SpecialRequestWiki ) {
			return true;
		}

		$specialPage->setContext( RequestContext::getMain() );
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
			'field' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
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
