<?php

namespace Miraheze\CreateWiki\RequestWiki;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\HTMLForm\HTMLFormField;
use MediaWiki\Linker\Linker;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\Permissions\PermissionManager;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\CreateWikiOOUIForm;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use UserNotLoggedIn;

class RequestWikiRequestViewer {

	private Config $config;
	private IContextSource $context;
	private CreateWikiHookRunner $hookRunner;
	private PermissionManager $permissionManager;
	private WikiManagerFactory $wikiManagerFactory;
	private WikiRequestManager $wikiRequestManager;

	private array $extraFields = [];

	public function __construct(
		Config $config,
		IContextSource $context,
		CreateWikiHookRunner $hookRunner,
		PermissionManager $permissionManager,
		WikiManagerFactory $wikiManagerFactory,
		WikiRequestManager $wikiRequestManager
	) {
		$this->config = $config;
		$this->context = $context;
		$this->hookRunner = $hookRunner;
		$this->permissionManager = $permissionManager;
		$this->wikiManagerFactory = $wikiManagerFactory;
		$this->wikiRequestManager = $wikiRequestManager;
	}

	public function getFormDescriptor(): array {
		$user = $this->context->getUser();

		$visibilityConds = WikiRequestManager::VISIBILITY_CONDS;

		// if request isn't found, it doesn't exist
		// but if we can't view the request, it also doesn't exist

		// T12010: 3 is a legacy suppression level, treat it as a suppressed request hidden from everyone
		if ( $this->wikiRequestManager->getVisibility() >= 3 ) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'requestwiki-unknown' )->escaped() )
			);

			return [];
		}

		if ( $visibilityConds[$this->wikiRequestManager->getVisibility()] !== 'public' ) {
			if ( !$this->permissionManager->userHasRight( $user,
				$visibilityConds[$this->wikiRequestManager->getVisibility()]
			) ) {
				$this->context->getOutput()->addHTML(
					Html::errorBox( $this->context->msg( 'requestwiki-unknown' )->escaped() )
				);

				return [];
			}
		}

		if ( $this->wikiRequestManager->isLocked() ) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'createwiki-request-locked' )->escaped() )
			);
		}

		$formDescriptor = [
			'sitename' => [
				'label-message' => 'requestwikiqueue-request-label-sitename',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->wikiRequestManager->getSitename(),
			],
			'url' => [
				'label-message' => 'requestwikiqueue-request-label-url',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->wikiRequestManager->getUrl(),
			],
			'language' => [
				'label-message' => 'requestwikiqueue-request-label-language',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->wikiRequestManager->getLanguage(),
			],
			'requester' => [
				'label-message' => 'requestwikiqueue-request-label-requester',
				'type' => 'info',
				'section' => 'details',
				'default' => htmlspecialchars( $this->wikiRequestManager->getRequester()->getName() ) .
					Linker::userToolLinks(
						$this->wikiRequestManager->getRequester()->getId(),
						$this->wikiRequestManager->getRequester()->getName()
					),
				'raw' => true,
			],
			'requestedDate' => [
				'label-message' => 'requestwikiqueue-request-label-requested-date',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->context->getLanguage()->timeanddate(
					$this->wikiRequestManager->getTimestamp(), true
				),
			],
			'status' => [
				'label-message' => 'requestwikiqueue-request-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->context->msg(
					'requestwikiqueue-' . $this->wikiRequestManager->getStatus()
				)->text(),
			],
			'description' => [
				'type' => 'textarea',
				'rows' => 6,
				'readonly' => true,
				'label-message' => 'requestwikiqueue-request-header-description',
				'section' => 'details',
				'default' => $this->wikiRequestManager->getDescription(),
				'raw' => true,
			],
		];

		foreach ( $this->wikiRequestManager->getComments() as $comment ) {
			$formDescriptor['comment' . $comment['timestamp'] ] = [
				'type' => 'textarea',
				'readonly' => true,
				'section' => 'comments',
				'rows' => 6,
				'label-message' => [
					'requestwiki-header-comment-withtimestamp',
					$comment['user']->getName(),
					$this->context->getLanguage()->timeanddate( $comment['timestamp'], true ),
				],
				'default' => $comment['comment'],
			];
		}

		if (
			$this->permissionManager->userHasRight( $user, 'createwiki' ) ||
			$user->getActorId() === $this->wikiRequestManager->getRequester()->getActorId()
		) {
			$formDescriptor += [
				'comment' => [
					'type' => 'textarea',
					'rows' => 6,
					'label-message' => 'requestwikiqueue-request-label-comment',
					'section' => 'comments',
					'validation-callback' => [ $this, 'isValidComment' ],
					'disabled' => $this->wikiRequestManager->isLocked(),
				],
				'submit-comment' => [
					'type' => 'submit',
					'buttonlabel-message' => 'requestwiki-label-add-comment',
					'disabled' => $this->wikiRequestManager->isLocked(),
					'section' => 'comments',
				],
				'edit-sitename' => [
					'label-message' => 'requestwikiqueue-request-label-sitename',
					'type' => 'text',
					'section' => 'edit',
					'required' => true,
					'default' => $this->wikiRequestManager->getSitename(),
					'disabled' => $this->wikiRequestManager->isLocked(),
				],
				'edit-url' => [
					'label-message' => 'requestwikiqueue-request-label-url',
					'type' => 'text',
					'section' => 'edit',
					'required' => true,
					'default' => $this->wikiRequestManager->getUrl(),
					'validation-callback' => [ $this, 'isValidSubdomain' ],
					'disabled' => $this->wikiRequestManager->isLocked(),
				],
				'edit-language' => [
					'label-message' => 'requestwikiqueue-request-label-language',
					'type' => 'language',
					'default' => $this->wikiRequestManager->getLanguage(),
					'disabled' => $this->wikiRequestManager->isLocked(),
					'cssclass' => 'createwiki-infuse',
					'section' => 'edit',
				],
				'edit-description' => [
					'label-message' => 'requestwikiqueue-request-header-description',
					'type' => 'textarea',
					'section' => 'edit',
					'rows' => 6,
					'required' => true,
					'default' => $this->wikiRequestManager->getDescription(),
					'disabled' => $this->wikiRequestManager->isLocked(),
					'raw' => true,
				],
			];

			if ( $this->config->get( ConfigNames::Categories ) ) {
				$formDescriptor['edit-category'] = [
					'type' => 'select',
					'label-message' => 'createwiki-label-category',
					'options' => $this->config->get( ConfigNames::Categories ),
					'default' => $this->wikiRequestManager->getCategory(),
					'disabled' => $this->wikiRequestManager->isLocked(),
					'cssclass' => 'createwiki-infuse',
					'section' => 'edit',
				];
			}

			if ( $this->config->get( ConfigNames::UsePrivateWikis ) ) {
				$formDescriptor['edit-private'] = [
					'type' => 'check',
					'label-message' => 'requestwiki-label-private',
					'default' => $this->wikiRequestManager->isPrivate(),
					'disabled' => $this->wikiRequestManager->isLocked(),
					'section' => 'edit',
				];
			}

			if ( $this->config->get( ConfigNames::ShowBiographicalOption ) ) {
				$formDescriptor['edit-bio'] = [
					'type' => 'check',
					'label-message' => 'requestwiki-label-bio',
					'default' => $this->wikiRequestManager->isBio(),
					'disabled' => $this->wikiRequestManager->isLocked(),
					'section' => 'edit',
				];
			}

			if ( $this->config->get( ConfigNames::Purposes ) ) {
				$formDescriptor['edit-purpose'] = [
					'type' => 'select',
					'label-message' => 'requestwiki-label-purpose',
					'options' => $this->config->get( ConfigNames::Purposes ),
					'default' => $this->wikiRequestManager->getPurpose(),
					'disabled' => $this->wikiRequestManager->isLocked(),
					'cssclass' => 'createwiki-infuse',
					'section' => 'edit',
				];
			}

			$formDescriptor['submit-edit'] = [
				'type' => 'submit',
				'buttonlabel-message' => 'requestwiki-label-edit-request',
				'disabled' => $this->wikiRequestManager->isLocked(),
				'section' => 'edit',
			];
		}

		// TODO: Should we really require (createwiki) to suppress wiki requests?
		if ( $this->permissionManager->userHasRight( $user, 'createwiki' ) && !$user->getBlock() ) {
			foreach ( $this->wikiRequestManager->getRequestHistory() as $entry ) {
				$timestamp = $this->context->getLanguage()->timeanddate( $entry['timestamp'], true );
				$formDescriptor[ 'history-' . $entry['timestamp'] ] = [
					'type' => 'textarea',
					'readonly' => true,
					'section' => 'history',
					'rows' => 6,
					'label' => $entry['user']->getName() . ' | ' . ucfirst( $entry['action'] ) . ' | ' . $timestamp,
					'default' => $entry['details'],
				];
			}

			// You can't even get to this part in suppressed wiki requests without the appropiate userright,
			// so it is OK for the undelete/unsuppress option to be here
			$visibilityOptions = [
				0 => $this->context->msg( 'requestwikiqueue-request-label-visibility-all' )->escaped(),
			];

			if ( $this->permissionManager->userHasRight( $user, 'createwiki-deleterequest' ) ) {
				$visibilityOptions[1] = $this->context->msg(
					'requestwikiqueue-request-label-visibility-delete'
				)->escaped();
			}

			if ( $this->permissionManager->userHasRight( $user, 'createwiki-suppressrequest' ) ) {
				$visibilityOptions[2] = $this->context->msg(
					'requestwikiqueue-request-label-visibility-suppress'
				)->escaped();
			}

			$wikiManager = $this->wikiManagerFactory->newInstance( $this->wikiRequestManager->getDBname() );
			$error = $wikiManager->checkDatabaseName( $this->wikiRequestManager->getDBname(), forRename: false );

			if ( $error ) {
				$this->context->getOutput()->addHTML( Html::errorBox( $error ) );
			}

			if ( $this->config->get( ConfigNames::RequestCountWarnThreshold ) ) {
				$requestCount = count( $this->wikiRequestManager->getVisibleRequestsByUser(
					$this->wikiRequestManager->getRequester(), $user
				) );

				if ( $requestCount >= $this->config->get( ConfigNames::RequestCountWarnThreshold ) ) {
					$this->context->getOutput()->addHTML(
						Html::warningBox( $this->context->msg( 'createwiki-error-requestcountwarn',
							$requestCount, $this->wikiRequestManager->getRequester()->getName()
						)->parse() )
					);
				}
			}

			$formDescriptor += [
				'handle-info' => [
					'type' => 'info',
					'default' => $this->context->msg( 'requestwikiqueue-request-info-submission' )->text(),
					'section' => 'handling',
				],
				'handle-lock' => [
					'type' => 'check',
					'label-message' => 'createwiki-label-lock',
					'default' => $this->wikiRequestManager->isLocked(),
					'section' => 'handling',
				],
				'handle-action' => [
					'type' => 'radio',
					'label-message' => 'requestwikiqueue-request-label-action',
					'options-messages' => [
						'requestwikiqueue-onhold' => 'onhold',
						'requestwikiqueue-approve' => 'approve',
						'requestwikiqueue-decline' => 'decline',
						'requestwikiqueue-moredetails' => 'moredetails',
					],
					'default' => $this->wikiRequestManager->getStatus(),
					'cssclass' => 'createwiki-infuse',
					'section' => 'handling',
				],
				'handle-comment' => [
					'label-message' => 'createwiki-label-statuschangecomment',
					'section' => 'handling',
				],
				'handle-visibility' => [
					'type' => 'check',
					'label-message' => 'revdelete-legend',
					'default' => ( $this->wikiRequestManager->getVisibility() !== 0 ) ? 1 : 0,
					'cssclass' => 'createwiki-infuse',
					'section' => 'handling',
				],
				'handle-visibility-options' => [
					'type' => 'radio',
					'label-message' => 'revdelete-suppress-text',
					'hide-if' => [ '!==', 'handle-visibility', '1' ],
					'options' => array_flip( $visibilityOptions ),
					'default' => (string)$this->wikiRequestManager->getVisibility(),
					'cssclass' => 'createwiki-infuse',
					'section' => 'handling',
				],
				'submit-handle' => [
					'type' => 'submit',
					'buttonlabel-message' => 'htmlform-submit',
					'section' => 'handling',
				],
			];

			if ( $this->config->get( ConfigNames::CannedResponses ) ) {
				$formDescriptor['handle-comment']['type'] = 'selectorother';
				$formDescriptor['handle-comment']['options'] = $this->config->get( ConfigNames::CannedResponses );

				$formDescriptor['handle-comment']['default'] = HTMLFormField::flattenOptions(
					$this->config->get( ConfigNames::CannedResponses )
				)[0];
			} else {
				$formDescriptor['handle-comment']['type'] = 'textarea';
				$formDescriptor['handle-comment']['rows'] = 6;
			}

			if ( $error ) {
				// We don't want to be able to approve it if the database is not valid
				unset( $formDescriptor['handle-action']['options-messages']['requestwikiqueue-approve'] );
			}
		}

		// We store the original formDescriptor here so we
		// can find any extra fields added via hook. We do this
		// so we can store to the extraFields property and differentiate
		// if we should store via cw_extra in submitForm().
		$baseFormDescriptor = $formDescriptor;

		$this->hookRunner->onRequestWikiQueueFormDescriptorModify(
			$formDescriptor,
			$user,
			$this->wikiRequestManager
		);

		// We get all the keys from $formDescriptor whose keys are
		// absent from $baseFormDescriptor.
		$this->extraFields = array_diff_key( $formDescriptor, $baseFormDescriptor );

		return $formDescriptor;
	}

	/**
	 * @param int $requestID
	 * @return ?CreateWikiOOUIForm
	 */
	public function getForm( int $requestID ): ?CreateWikiOOUIForm {
		$this->wikiRequestManager->fromID( $requestID );
		$out = $this->context->getOutput();

		if ( $requestID === 0 || !$this->wikiRequestManager->exists() ) {
			$out->addHTML(
				Html::errorBox( $this->context->msg( 'requestwiki-unknown' )->escaped() )
			);

			return null;
		}

		$out->addModules( [ 'ext.createwiki.oouiform' ] );
		$out->addModules( [ 'mediawiki.special.userrights' ] );
		$out->addModuleStyles( [ 'ext.createwiki.oouiform.styles' ] );
		$out->addModuleStyles( [ 'oojs-ui-widgets.styles' ] );

		$formDescriptor = $this->getFormDescriptor();
		$htmlForm = new CreateWikiOOUIForm( $formDescriptor, $this->context, 'requestwikiqueue' );

		$htmlForm->setId( 'createwiki-form' );
		$htmlForm->suppressDefaultSubmit();
		$htmlForm->setSubmitCallback(
			function ( array $formData, HTMLForm $form ) {
				return $this->submitForm( $formData, $form );
			}
		);

		return $htmlForm;
	}

	protected function submitForm(
		array $formData,
		HTMLForm $form
	): void {
		$user = $form->getUser();
		if ( !$user->isRegistered() ) {
			throw new UserNotLoggedIn( 'exception-nologin-text', 'exception-nologin' );
		}

		$out = $form->getContext()->getOutput();
		$session = $form->getRequest()->getSession();

		if ( isset( $formData['submit-comment'] ) ) {
			if ( $session->get( 'previous_posted_comment' ) !== $formData['comment'] ) {
				$session->set( 'previous_posted_comment', $formData['comment'] );
				$this->wikiRequestManager->addComment(
					comment: $formData['comment'],
					user: $user,
					log: true,
					type: 'comment'
				);
				$out->addHTML( Html::successBox( $this->context->msg( 'createwiki-comment-success' )->escaped() ) );
				return;
			}

			$out->addHTML( Html::errorBox( $this->context->msg( 'createwiki-duplicate-comment' )->escaped() ) );
			return;
		}

		$session->remove( 'previous_posted_comment' );

		if ( isset( $formData['submit-edit'] ) ) {
			if ( $this->wikiRequestManager->getStatus() === 'approved' ) {
				// TODO: can not edit already approved request message
				return;
			}

			$this->wikiRequestManager->startQueryBuilder();

			$this->wikiRequestManager->setSitename( $formData['edit-sitename'] );
			$this->wikiRequestManager->setLanguage( $formData['edit-language'] );
			$this->wikiRequestManager->setUrl( $formData['edit-url'] );
			$this->wikiRequestManager->setCategory( $formData['edit-category'] ?? '' );
			$this->wikiRequestManager->setPrivate( (bool)( $formData['edit-private'] ?? false ) );
			$this->wikiRequestManager->setBio( (bool)( $formData['edit-bio'] ?? false ) );

			// We do this at once since they are both stored in cw_comment
			$this->wikiRequestManager->setDescriptionAndPurpose(
				$formData['edit-description'],
				$formData['edit-purpose'] ?? ''
			);

			foreach ( $this->extraFields as $field => $value ) {
				if ( $formData[$field] ?? false ) {
					$this->wikiRequestManager->setExtraFieldData( $field, $formData[$field] );
				}
			}

			if ( !$this->wikiRequestManager->hasChanges() ) {
				$this->wikiRequestManager->clearQueryBuilder();
				$out->addHTML( Html::errorBox( $this->context->msg( 'createwiki-no-changes' )->escaped() ) );
				return;
			}

			$comment = $this->context->msg( 'createwiki-request-updated' )
				->inContentLanguage()->escaped();

			$this->wikiRequestManager->addComment(
				comment: $comment,
				user: $user,
				log: false,
				type: 'comment'
			);

			// Log the edit to request history
			$this->wikiRequestManager->addRequestHistory(
				action: 'edited',
				details: $this->wikiRequestManager->getChangeMessage(),
				user: $user
			);

			$this->wikiRequestManager->setStatus( 'inreview' );

			// Log if we are reopening the request
			if ( $this->wikiRequestManager->getStatus() === 'declined' ) {
				$this->wikiRequestManager->log( $user, 'requestreopen' );
			}

			$this->wikiRequestManager->tryExecuteQueryBuilder();
			$out->addHTML( $this->getResponseMessageBox() );
			return;
		}

		if ( isset( $formData['submit-handle'] ) ) {
			$this->wikiRequestManager->startQueryBuilder();

			if ( isset( $formData['handle-visibility-options'] ) ) {
				$this->wikiRequestManager->suppress(
					user: $user,
					level: $formData['handle-visibility-options'],
					log: true
				);
			}

			// Handle locking wiki request
			if ( $this->wikiRequestManager->isLocked() !== (bool)$formData['handle-lock'] ) {
				$this->wikiRequestManager->setLocked( (bool)$formData['handle-lock'] );
				$this->wikiRequestManager->tryExecuteQueryBuilder();
				return;
			}

			/**
			 * HANDLE STATUS UPDATES
			 */

			// Handle approve action
			if ( $formData['handle-action'] === 'approve' ) {
				// This will create the wiki
				$this->wikiRequestManager->approve( $user, $formData['handle-comment'] );
				$this->wikiRequestManager->tryExecuteQueryBuilder();
				$out->addHTML( $this->getResponseMessageBox() );
				return;
			}

			// Handle onhold action
			if ( $formData['handle-action'] === 'onhold' ) {
				$this->wikiRequestManager->onhold( $formData['handle-comment'], $user );
				$this->wikiRequestManager->tryExecuteQueryBuilder();
				$out->addHTML( $this->getResponseMessageBox() );
				return;
			}

			// Handle moredetails action
			if ( $formData['handle-action'] === 'moredetails' ) {
				$this->wikiRequestManager->moredetails( $formData['handle-comment'], $user );
				$this->wikiRequestManager->tryExecuteQueryBuilder();
				$out->addHTML( $this->getResponseMessageBox() );
				return;
			}

			// Handle decline action (the action we use if handle-action is none of the others)
			$this->wikiRequestManager->decline( $formData['handle-comment'], $user );
			$this->wikiRequestManager->tryExecuteQueryBuilder();
			$out->addHTML( $this->getResponseMessageBox() );
		}
	}

	public function isValidComment( ?string $comment, array $alldata ): bool|Message {
		if ( isset( $alldata['submit-comment'] ) && ( !$comment || ctype_space( $comment ) ) ) {
			return $this->context->msg( 'htmlform-required' );
		}

		return true;
	}

	public function isValidSubdomain( ?string $subdomain, array $alldata ): bool|Message {
		if ( !isset( $alldata['submit-edit'] ) ) {
			// If we aren't submitting an edit we don't want this to fail.
			// For example, we don't want an invalid subdomain to block
			// adding a comment.
			return true;
		}

		if ( !$subdomain || ctype_space( $subdomain ) ) {
			return $this->context->msg( 'htmlform-required' );
		}

		$subdomain = strtolower( $subdomain );
		$configSubdomain = $this->config->get( ConfigNames::Subdomain );

		if ( strpos( $subdomain, $configSubdomain ) !== false ) {
			$subdomain = str_replace( '.' . $configSubdomain, '', $subdomain );
		}

		$disallowedSubdomains = CreateWikiRegexConstraint::regexFromArrayOrString(
			$this->config->get( ConfigNames::DisallowedSubdomains ), '/^(', ')+$/',
			ConfigNames::DisallowedSubdomains
		);

		$database = $subdomain . $this->config->get( ConfigNames::DatabaseSuffix );

		if ( in_array( $database, $this->config->get( MainConfigNames::LocalDatabases ) ) ) {
			return $this->context->msg( 'createwiki-error-subdomaintaken' );
		}

		if ( !ctype_alnum( $subdomain ) ) {
			return $this->context->msg( 'createwiki-error-notalnum' );
		}

		if ( preg_match( $disallowedSubdomains, $subdomain ) ) {
			return $this->context->msg( 'createwiki-error-disallowed' );
		}

		return true;
	}

	private function getResponseMessageBox(): string {
		// We use this to reduce code duplication
		if ( $this->wikiRequestManager->hasChanges() ) {
			$this->wikiRequestManager->clearChanges();
			return Html::successBox(
				$this->context->msg( 'requestwiki-edit-success' )->escaped()
			);
		}

		return Html::errorBox(
			$this->context->msg( 'createwiki-no-changes' )->escaped()
		);
	}
}
