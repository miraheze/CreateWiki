<?php

namespace Miraheze\CreateWiki\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\HTMLForm\HTMLFormField;
use MediaWiki\Language\RawMessage;
use MediaWiki\Languages\LanguageNameUtils;
use MediaWiki\Linker\Linker;
use MediaWiki\Permissions\PermissionManager;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\CreateWikiOOUIForm;
use Miraheze\CreateWiki\Exceptions\UnknownRequestError;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\RequestWiki\FormFields\DetailsWithIconField;
use UserNotLoggedIn;
use function array_diff_key;
use function array_flip;
use function count;
use function nl2br;
use function str_starts_with;
use function strlen;
use function substr;
use function ucfirst;

class WikiRequestViewer {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::CannedResponses,
		ConfigNames::Categories,
		ConfigNames::Purposes,
		ConfigNames::RequestCountWarnThreshold,
		ConfigNames::ShowBiographicalOption,
		ConfigNames::UsePrivateWikis,
	];

	private array $extraFields = [];

	public function __construct(
		private readonly IContextSource $context,
		private readonly CreateWikiHookRunner $hookRunner,
		private readonly CreateWikiValidator $validator,
		private readonly LanguageNameUtils $languageNameUtils,
		private readonly PermissionManager $permissionManager,
		private readonly WikiRequestManager $wikiRequestManager,
		private readonly ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function getFormDescriptor(): array {
		$user = $this->context->getUser();

		// If request isn't found, it doesn't exist, but if we
		// can't view the request, it also doesn't exist.
		$visibility = $this->wikiRequestManager->getVisibility();
		if ( !$this->wikiRequestManager->isVisibilityAllowed( $visibility, $user ) ) {
			throw new UnknownRequestError();
		}

		if ( $this->wikiRequestManager->isLocked() ) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'createwiki-request-locked' )->escaped() )
			);
		}

		$formDescriptor = [
			'sitename' => [
				'label-message' => 'requestwikiqueue-request-label-sitename',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->wikiRequestManager->getSitename(),
			],
			'url' => [
				'label-message' => 'requestwikiqueue-request-label-url',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->wikiRequestManager->getUrl(),
			],
			'language' => [
				'label-message' => 'requestwikiqueue-request-label-language',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->languageNameUtils->getLanguageName(
					code: $this->wikiRequestManager->getLanguage(),
					inLanguage: $this->context->getLanguage()->getCode()
				),
			],
			'requester' => [
				'label-message' => 'requestwikiqueue-request-label-requester',
				'type' => 'info',
				'section' => 'details',
				'default' => Linker::userLink(
					$this->wikiRequestManager->getRequester()->getId(),
					$this->wikiRequestManager->getRequester()->getName()
				) . Linker::userToolLinks(
					$this->wikiRequestManager->getRequester()->getId(),
					$this->wikiRequestManager->getRequester()->getName()
				),
				'raw' => true,
			],
			'requestedDate' => [
				'label-message' => 'requestwikiqueue-request-label-requested-date',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->context->getLanguage()->userTimeAndDate(
					$this->wikiRequestManager->getTimestamp(), $user
				),
			],
			'status' => [
				'label-message' => 'requestwikiqueue-request-label-status',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->context->msg(
					'requestwikiqueue-' . $this->wikiRequestManager->getStatus()
				)->text(),
			],
			'reason' => [
				'type' => 'info',
				'label-message' => 'requestwikiqueue-request-label-reason',
				'section' => 'details',
				'default' => ( new RawMessage( nl2br( $this->wikiRequestManager->getReason() ) ) )->parse(),
				'raw' => true,
			],
			'private' => [
				'class' => DetailsWithIconField::class,
				'label-message' => 'requestwiki-label-private',
				'fieldCheck' => $this->wikiRequestManager->isPrivate(),
				'section' => 'details',
			],
			'bio' => [
				'class' => DetailsWithIconField::class,
				'label-message' => 'requestwiki-label-bio',
				'fieldCheck' => $this->wikiRequestManager->isBio(),
				'section' => 'details',
			],
		];

		foreach ( $this->wikiRequestManager->getComments() as $comment ) {
			$formDescriptor['comment' . $comment['timestamp'] ] = [
				'type' => 'info',
				'section' => 'comments',
				'label-message' => [
					'requestwiki-header-comment-withtimestamp',
					$comment['user']->getName(),
					$this->context->getLanguage()->userTimeAndDate( $comment['timestamp'], $user ),
				],
				'default' => ( new RawMessage( nl2br( $comment['comment'] ) ) )->parse(),
				'raw' => true,
			];
		}

		$canEditRequest = !$user->getBlock() && (
			$this->permissionManager->userHasRight( $user, 'createwiki' ) ||
			$user->getActorId() === $this->wikiRequestManager->getRequester()->getActorId()
		);

		if ( $canEditRequest ) {
			$formDescriptor += [
				'comment' => [
					'type' => 'textarea',
					'rows' => 10,
					'label-message' => 'requestwikiqueue-request-label-comment',
					'section' => 'comments',
					'validation-callback' => [ $this->validator, 'validateComment' ],
					'useeditfont' => true,
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
					'section' => 'editing',
					'required' => true,
					'default' => $this->wikiRequestManager->getSitename(),
					'disabled' => $this->wikiRequestManager->isLocked(),
					// https://github.com/miraheze/CreateWiki/blob/20c2f47/sql/cw_requests.sql#L7
					'maxlength' => 128,
				],
				'edit-url' => [
					'label-message' => 'requestwikiqueue-request-label-url',
					'type' => 'text',
					'section' => 'editing',
					'required' => true,
					'default' => $this->wikiRequestManager->getUrl(),
					'validation-callback' => [ $this->validator, 'validateSubdomain' ],
					'disabled' => $this->wikiRequestManager->isLocked(),
					// https://github.com/miraheze/CreateWiki/blob/20c2f47/sql/cw_requests.sql#L10
					'maxlength' => 96,
				],
				'edit-language' => [
					'label-message' => 'requestwikiqueue-request-label-language',
					'type' => 'language',
					'default' => $this->wikiRequestManager->getLanguage(),
					'disabled' => $this->wikiRequestManager->isLocked(),
					'cssclass' => 'createwiki-infuse',
					'section' => 'editing',
				],
				'edit-reason' => [
					'label-message' => 'requestwikiqueue-request-label-reason',
					'type' => 'textarea',
					'section' => 'editing',
					'rows' => 10,
					'required' => true,
					'useeditfont' => true,
					'default' => $this->wikiRequestManager->getReason(),
					'disabled' => $this->wikiRequestManager->isLocked(),
					'validation-callback' => [ $this->validator, 'validateReason' ],
				],
			];

			if ( $this->options->get( ConfigNames::Categories ) ) {
				$formDescriptor['edit-category'] = [
					'type' => 'select',
					'label-message' => 'createwiki-label-category',
					'options' => $this->options->get( ConfigNames::Categories ),
					'default' => $this->wikiRequestManager->getCategory(),
					'disabled' => $this->wikiRequestManager->isLocked(),
					'cssclass' => 'createwiki-infuse',
					'section' => 'editing',
				];
			}

			if ( $this->options->get( ConfigNames::UsePrivateWikis ) ) {
				$formDescriptor['edit-private'] = [
					'type' => 'check',
					'label-message' => 'requestwiki-label-private',
					'default' => $this->wikiRequestManager->isPrivate(),
					'disabled' => $this->wikiRequestManager->isLocked(),
					'section' => 'editing',
				];
			}

			if ( $this->options->get( ConfigNames::ShowBiographicalOption ) ) {
				$formDescriptor['edit-bio'] = [
					'type' => 'check',
					'label-message' => 'requestwiki-label-bio',
					'default' => $this->wikiRequestManager->isBio(),
					'disabled' => $this->wikiRequestManager->isLocked(),
					'section' => 'editing',
				];
			}

			if ( $this->options->get( ConfigNames::Purposes ) ) {
				$formDescriptor['edit-purpose'] = [
					'type' => 'select',
					'label-message' => 'requestwiki-label-purpose',
					'required' => true,
					'options' => $this->options->get( ConfigNames::Purposes ),
					'default' => $this->wikiRequestManager->getPurpose(),
					'disabled' => $this->wikiRequestManager->isLocked(),
					'cssclass' => 'createwiki-infuse',
					'section' => 'editing',
				];
			}

			$formDescriptor['submit-edit'] = [
				'type' => 'submit',
				'buttonlabel-message' => 'requestwiki-label-edit-request',
				'disabled' => $this->wikiRequestManager->isLocked(),
				'section' => 'editing',
			];
		}

		$canHandleRequest = $this->permissionManager->userHasRight( $user, 'createwiki' ) && !$user->getBlock();
		if ( $canHandleRequest ) {
			foreach ( $this->wikiRequestManager->getRequestHistory() as $entry ) {
				$timestamp = $this->context->getLanguage()->userTimeAndDate( $entry['timestamp'], $user );
				$formDescriptor[ 'history-' . $entry['timestamp'] ] = [
					'type' => 'info',
					'section' => 'history',
					'label' => $entry['user']->getName() . ' | ' . ucfirst( $entry['action'] ) . ' | ' . $timestamp,
					'default' => ( new RawMessage( nl2br( $entry['details'] ) ) )->parse(),
					'raw' => true,
				];
			}

			// You can't even get to this part in suppressed wiki requests without the appropiate userright,
			// so it is OK for the undelete/unsuppress option to be here
			$visibilityOptions = [
				WikiRequestManager::VISIBILITY_PUBLIC => $this->context->msg(
					'requestwikiqueue-request-label-visibility-all'
				)->escaped(),
			];

			if ( $this->permissionManager->userHasRight( $user, 'createwiki-deleterequest' ) ) {
				$visibilityOptions[WikiRequestManager::VISIBILITY_DELETE_REQUEST] = $this->context->msg(
					'requestwikiqueue-request-label-visibility-delete'
				)->escaped();
			}

			if ( $this->permissionManager->userHasRight( $user, 'createwiki-suppressrequest' ) ) {
				$visibilityOptions[WikiRequestManager::VISIBILITY_SUPPRESS_REQUEST] = $this->context->msg(
					'requestwikiqueue-request-label-visibility-suppress'
				)->escaped();
			}

			$dbname = $this->wikiRequestManager->getDBname();
			$exists = $this->validator->databaseExists( $dbname );

			$error = $this->validator->validateDatabaseName( $dbname, $exists );

			if ( $error ) {
				$this->context->getOutput()->addHTML( Html::errorBox( $error ) );
			}

			if ( $this->options->get( ConfigNames::RequestCountWarnThreshold ) ) {
				$requestCount = count( $this->wikiRequestManager->getVisibleRequestsByUser(
					$this->wikiRequestManager->getRequester(), $user
				) );

				if ( $requestCount >= $this->options->get( ConfigNames::RequestCountWarnThreshold ) ) {
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
					'validation-callback' => [ $this->validator, 'validateStatusComment' ],
					'section' => 'handling',
				],
				'handle-lock' => [
					'type' => 'check',
					'label-message' => 'createwiki-label-lock',
					'default' => $this->wikiRequestManager->isLocked(),
					'section' => 'handling',
				],
				'handle-changevisibility' => [
					'type' => 'check',
					'label-message' => 'revdelete-legend',
					'default' => $visibility !== WikiRequestManager::VISIBILITY_PUBLIC,
					'cssclass' => 'createwiki-infuse',
					'section' => 'handling',
				],
				'handle-visibility' => [
					'type' => 'radio',
					'label-message' => 'revdelete-suppress-text',
					'hide-if' => [ '!==', 'handle-changevisibility', '1' ],
					'options' => array_flip( $visibilityOptions ),
					'default' => (string)$visibility,
					'cssclass' => 'createwiki-infuse',
					'section' => 'handling',
				],
				'submit-handle' => [
					'type' => 'submit',
					'buttonlabel-message' => 'htmlform-submit',
					'section' => 'handling',
				],
			];

			if ( $this->options->get( ConfigNames::CannedResponses ) ) {
				$formDescriptor['handle-comment']['type'] = 'selectorother';
				$formDescriptor['handle-comment']['options'] = $this->options->get( ConfigNames::CannedResponses );

				$formDescriptor['handle-comment']['default'] = HTMLFormField::flattenOptions(
					$this->options->get( ConfigNames::CannedResponses )
				)[0];
			} else {
				$formDescriptor['handle-comment']['type'] = 'textarea';
				$formDescriptor['handle-comment']['rows'] = 10;
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

		// Ensure extra fields added via hooks adhere to proper permission checks
		foreach ( $this->extraFields as $field => $properties ) {
			if ( ( $properties['save'] ?? null ) === false ) {
				unset( $this->extraFields[$field] );
			}

			$section = $properties['section'] ?? '';
			$type = $properties['type'] ?? '';

			if ( $section === 'editing' || str_starts_with( $section, 'editing/' ) ) {
				if ( !$canEditRequest ) {
					unset( $formDescriptor[$field] );
					continue;
				}

				if ( $this->wikiRequestManager->isLocked() ) {
					$formDescriptor[$field]['disabled'] = true;
				}
				continue;
			}

			if ( !$canHandleRequest && $section === 'handling' ) {
				unset( $formDescriptor[$field] );
			}
		}

		return $formDescriptor;
	}

	public function getForm( int $requestID ): CreateWikiOOUIForm {
		$this->wikiRequestManager->loadFromID( $requestID );
		$out = $this->context->getOutput();

		if ( $requestID === 0 || !$this->wikiRequestManager->exists() ) {
			throw new UnknownRequestError();
		}

		$out->addModules( [ 'ext.createwiki.oouiform' ] );
		$out->addModules( [ 'mediawiki.special.userrights' ] );
		$out->addModuleStyles( [ 'ext.createwiki.oouiform.styles' ] );
		$out->addModuleStyles( [ 'oojs-ui-widgets.styles' ] );

		$formDescriptor = $this->getFormDescriptor();
		$htmlForm = new CreateWikiOOUIForm( $formDescriptor, $this->context, 'requestwikiqueue-section' );

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
			// Don't want to mess with some generic comments across requests.
			// If it is a different request it is not a duplicate comment.
			$ID = (string)$this->wikiRequestManager->getID();
			$commentData = $ID . ':' . $formData['comment'];
			if ( $session->get( 'previous_posted_comment' ) !== $commentData ) {
				$session->set( 'previous_posted_comment', $commentData );
				$this->wikiRequestManager->addComment(
					comment: $formData['comment'],
					user: $user,
					log: true,
					type: 'comment',
					// Use all involved users
					notifyUsers: []
				);

				$canCommentReopen = $this->wikiRequestManager->canCommentReopen() &&
					$user->getActorId() === $this->wikiRequestManager->getRequester()->getActorId();

				// Handle reopening the request if we should
				if ( $canCommentReopen ) {
					$this->wikiRequestManager->startQueryBuilder();
					$this->wikiRequestManager->setStatus( 'inreview' );
					$this->wikiRequestManager->tryExecuteQueryBuilder();

					$this->wikiRequestManager->log( $user, 'requestreopen' );
				}

				$out->addHTML( Html::successBox( $this->context->msg( 'createwiki-comment-success' )->escaped() ) );
				return;
			}

			$out->addHTML( Html::errorBox( $this->context->msg( 'createwiki-duplicate-comment' )->escaped() ) );
			return;
		}

		$session->remove( 'previous_posted_comment' );

		if ( isset( $formData['submit-edit'] ) ) {
			if ( $this->wikiRequestManager->getStatus() === 'approved' ) {
				$out->addHTML( Html::errorBox(
					$this->context->msg( 'createwiki-error-cannot-edit-approved' )->escaped()
				) );
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
			$this->wikiRequestManager->setReasonAndPurpose(
				$formData['edit-reason'],
				$formData['edit-purpose'] ?? ''
			);

			$extraData = [];
			foreach ( $this->extraFields as $field => $value ) {
				if ( isset( $formData[$field] ) ) {
					$fieldKey = $field;
					if ( str_starts_with( $field, 'edit-' ) ) {
						// Remove 'edit-' from the start of the field key
						$fieldKey = substr( $field, strlen( 'edit-' ) );
					}

					$extraData[$fieldKey] = $formData[$field];
				}
			}

			$this->wikiRequestManager->setExtraFieldsData( $extraData );

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
				type: 'comment',
				// Use all involved users
				notifyUsers: []
			);

			$canEditReopen = $this->wikiRequestManager->canEditReopen();

			// Log the edit or reopen to request history
			$this->wikiRequestManager->addRequestHistory(
				action: $canEditReopen ? 'reopened' : 'edited',
				details: $this->wikiRequestManager->getChangeMessage(),
				user: $user
			);

			// Handle reopening the request if we should
			if ( $canEditReopen ) {
				$this->wikiRequestManager->setStatus( 'inreview' );
				$this->wikiRequestManager->log( $user, 'requestreopen' );
			}

			$this->wikiRequestManager->tryExecuteQueryBuilder();
			$out->addHTML( $this->getResponseMessageBox() );
			return;
		}

		if ( isset( $formData['submit-handle'] ) ) {
			$this->wikiRequestManager->startQueryBuilder();

			if ( isset( $formData['handle-visibility'] ) ) {
				if ( $this->wikiRequestManager->getVisibility() !== (int)$formData['handle-visibility'] ) {
					$this->wikiRequestManager->suppress(
						user: $user,
						level: $formData['handle-visibility'],
						log: true
					);
				}
			}

			// Handle locking wiki request
			if ( $this->wikiRequestManager->isLocked() !== (bool)$formData['handle-lock'] ) {
				$this->wikiRequestManager->setLocked( (bool)$formData['handle-lock'] );
				$this->wikiRequestManager->tryExecuteQueryBuilder();
				if ( $formData['handle-lock'] ) {
					$out->addHTML( Html::successBox(
						$this->context->msg( 'createwiki-success-locked' )->escaped()
					) );
					return;
				}

				$out->addHTML( Html::successBox(
					$this->context->msg( 'createwiki-success-unlocked' )->escaped()
				) );
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
