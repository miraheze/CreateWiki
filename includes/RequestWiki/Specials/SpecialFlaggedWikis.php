<?php

namespace Miraheze\CreateWiki\RequestWiki\Specials;

use ErrorPageError;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Message\Message;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Specials\SpecialUserRights;
use MediaWiki\User\UserFactory;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\RequestWiki\FlaggedWikisPager;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Wikimedia\Rdbms\IConnectionProvider;
use XmlSelect;

class SpecialFlaggedWikis extends SpecialPage {

	private IConnectionProvider $connectionProvider;
	private PermissionManager $permissionManager;
	private UserFactory $userFactory;
	private WikiManagerFactory $wikiManagerFactory;

	public function __construct(
		IConnectionProvider $connectionProvider,
		PermissionManager $permissionManager,
		UserFactory $userFactory,
		WikiManagerFactory $wikiManagerFactory
	) {
		parent::__construct( 'FlaggedWikis', 'createwiki' );

		$this->connectionProvider = $connectionProvider;
		$this->permissionManager = $permissionManager;
		$this->userFactory = $userFactory;
		$this->wikiManagerFactory = $wikiManagerFactory;
	}

	/**
	 * @param ?string $par
	 */
	public function execute( $par ): void {
		if ( !WikiMap::isCurrentWikiId( $this->getConfig()->get( ConfigNames::GlobalWiki ) ) ) {
			throw new ErrorPageError( 'errorpagetitle', 'createwiki-wikinotglobalwiki' );
		}

		$this->setHeaders();
		$this->doPagerStuff();
	}

	private function doPagerStuff(): void {
		$expiryOptionsMsg = $this->msg( 'userrights-expiry-options' )->inContentLanguage();
		$expiryOptions = $expiryOptionsMsg->isDisabled()
			? []
			: XmlSelect::parseOptionsMessage( $expiryOptionsMsg->text() );
		$formDescriptor = [
			'intro' => [
				'type' => 'info',
				'default' => $this->msg( 'flaggedwikis-info' )->text(),
			],
			'dbname' => [
				'type' => 'text',
				'label-message' => 'createwiki-flaggedwikis-label-new-dbname',
				'required' => true,
				'validation-callback' => [ $this, 'isValidDBname' ],
			],
			'reason' => [
				'type' => 'text',
				'label-message' => 'createwiki-flaggedwikis-label-new-reason',
				'required' => true,
				'validation-callback' => [ $this, 'isValidReason' ],
			],
			'expiry' => [
				'type' => 'expiry',
				'label-message' => 'createwiki-flaggedwikis-label-new-expiry',
				'default' => 0,
				'options' => [
					$this->msg( 'userrights-expiry-none' )->text() => 0,
					$this->msg( 'userrights-expiry-othertime' )->text() => 'other',
				] + $expiryOptions,
				'filter-callback' => static fn ( mixed $expiry ): int =>
					(int)SpecialUserrights::expiryToTimestamp( $expiry ),
			],
			'submit' => [
				'type' => 'submit',
				'buttonlabel-message' => 'createwiki-flaggedwikis-label-new-submit',
				'required' => true,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->suppressDefaultSubmit();
		$htmlForm->setSubmitCallback(
			function ( array $formData, HTMLForm $form ) {
				return $this->submitForm( $formData, $form );
			}
		);

		$htmlForm->show();

		$pager = new FlaggedWikisPager(
			$this->getConfig(),
			$this->getContext(),
			$this->connectionProvider,
			$this->getLinkRenderer(),
			$this->permissionManager,
			$this->userFactory,
			$this->wikiManagerFactory
		);

		$table = $pager->getFullOutput();
		$this->getOutput()->addParserOutputContent( $table );
	}

	protected function submitForm(
		array $formData,
		HTMLForm $form
	): void {
		$dbw = $this->connectionProvider->getPrimaryDatabase(
			$this->getConfig()->get( ConfigNames::GlobalWiki )
		);

		$dbw->newInsertQueryBuilder()
			->insertInto( 'cw_flags' )
			->row( [
				// No request — We are adding directly
				'cw_id' => 0,
				'cw_flag_actor' => $this->getUser()->getActorId(),
				'cw_flag_dbname' => $formData['dbname'],
				'cw_flag_reason' => $formData['reason'],
				'cw_flag_timestamp' => $dbw->timestamp(),
				'cw_flag_visibility' => 0,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	public function isValidDBname( ?string $dbname ): bool|Message {
		if ( !$dbname || ctype_space( $dbname ) ) {
			return $this->msg( 'htmlform-required' );
		}

		$wikiManager = $this->wikiManagerFactory->newInstance( $dbname );
		if ( !$wikiManager->exists() ) {
			return $this->msg( 'createwiki-error-missingwiki', $dbname );
		}

		return true;
	}

	public function isValidReason( ?string $reason ): bool|Message {
		if ( !$reason || ctype_space( $reason ) ) {
			return $this->msg( 'htmlform-required' );
		}

		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'wikimanage';
	}
}
