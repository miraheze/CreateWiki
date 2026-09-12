<?php

namespace Miraheze\CreateWiki\RequestWiki\Specials;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Exception\UserBlockedError;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\RequestWiki\RequestWikiFormDescriptorBuilder;
use Miraheze\CreateWiki\RequestWiki\RequestWikiWizardForm;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\CreateWikiParsedMessageCache;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Wikimedia\Stats\StatsFactory;
use function version_compare;
use const MW_VERSION;

class SpecialRequestWiki extends FormSpecialPage {

	private array $extraFields = [];

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CreateWikiParsedMessageCache $parsedMessageCache,
		private readonly RequestWikiFormDescriptorBuilder $formDescriptorBuilder,
		private readonly StatsFactory $statsFactory,
		private readonly WikiRequestManager $wikiRequestManager,
	) {
		if ( version_compare( MW_VERSION, '1.46', '>=' ) ) {
			parent::__construct( 'RequestWiki' );
		} else {
			parent::__construct( 'RequestWiki', 'requestwiki' );
		}
	}

	/**
	 * @param ?string $par
	 * @throws ErrorPageError
	 */
	public function execute( $par ): void {
		$this->requireNamedUser( 'requestwiki-notloggedin' );
		$this->setParameter( $par );
		$this->setHeaders();

		if ( !$this->databaseUtils->isCurrentWikiCentral() ) {
			throw new ErrorPageError( 'errorpagetitle', 'createwiki-wikinotcentralwiki' );
		}

		$requiresConfirmedEmail = $this->getConfig()->get( ConfigNames::RequestWikiConfirmEmail );
		if ( $requiresConfirmedEmail && !$this->getUser()->isEmailConfirmed() ) {
			throw new ErrorPageError( 'requestwiki', 'requestwiki-error-emailnotconfirmed' );
		}

		$this->checkPermissions();

		$this->getOutput()->addModules( [ 'ext.createwiki.requestwiki.wizard' ] );
		$this->getOutput()->addModuleStyles( [ 'ext.createwiki.requestwiki.wizard.styles' ] );

		if ( $this->getForm()->show() ) {
			$this->onSuccess();
		}
	}

	/** @inheritDoc */
	protected function getFormFields(): array {
		$built = $this->formDescriptorBuilder->build( $this->getContext() );
		$this->extraFields = $built['extraFields'];
		return $built['descriptor'];
	}

	/** @inheritDoc */
	protected function getForm(): RequestWikiWizardForm {
		$form = new RequestWikiWizardForm(
			$this->getFormFields(),
			$this->getContext(),
			$this->getMessagePrefix()
		);

		$form->setSubmitCallback( [ $this, 'onSubmit' ] );

		$headerMsg = $this->msg( $this->getMessagePrefix() . '-text' );
		if ( !$headerMsg->isDisabled() ) {
			$form->addHeaderHtml( $this->parsedMessageCache->parseAsBlock( $headerMsg ) );
		}

		$form->addPreHtml( $this->preHtml() );
		$form->addPostHtml( $this->postHtml() );
		$form->setTitle( $this->getPageTitle() );

		$this->alterForm( $form );
		return $form;
	}

	/** @inheritDoc */
	public function onSubmit( array $data ): Status {
		$token = $this->getRequest()->getVal( 'wpEditToken' );
		$userToken = $this->getContext()->getCsrfTokenSet();

		if ( !$userToken->matchToken( $token ) ) {
			return Status::newFatal( 'sessionfailure' );
		}

		if ( $this->getUser()->pingLimiter( 'requestwiki' ) ) {
			$this->statsFactory->getCounter( 'requestwiki_throttled_total' )->increment();
			return Status::newFatal( 'requestwiki-throttled' );
		}

		if ( $this->wikiRequestManager->isDuplicateRequest( $data['sitename'] ) ) {
			return Status::newFatal( 'requestwiki-error-patient' );
		}

		$extraData = [];
		foreach ( $this->extraFields as $field => $_ ) {
			if ( isset( $data[$field] ) ) {
				$extraData[$field] = $data[$field];
			}
		}

		$this->wikiRequestManager->createNewRequestAndLog( $data, $extraData, $this->getUser() );
		return Status::newGood();
	}

	/** @inheritDoc */
	public function onSuccess(): void {
		$requestId = (string)$this->wikiRequestManager->getId();
		$requestLink = SpecialPage::getTitleFor( 'RequestWikiQueue', $requestId );

		$this->getOutput()->redirect( $requestLink->getFullURL() );
		$this->statsFactory->getCounter( 'requestwiki_requests_total' )->increment();
	}

	/** @throws UserBlockedError */
	public function checkPermissions(): void {
		parent::checkPermissions();

		$user = $this->getUser();
		$block = $user->getBlock();
		if ( $block && ( $block->isSitewide() || $block->appliesToRight( 'requestwiki' ) ) ) {
			throw new UserBlockedError( $block, $user );
		}

		$this->checkReadOnly();
	}

	/** @inheritDoc */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'wiki';
	}

	/** @inheritDoc */
	public function getRestriction(): string {
		return 'requestwiki';
	}

	/** @inheritDoc */
	public function doesWrites(): bool {
		return true;
	}
}
