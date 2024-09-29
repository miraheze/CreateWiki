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
use Miraheze\CreateWiki\CreateWikiOOUIForm;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Miraheze\CreateWiki\Services\WikiRequestManager;

class RequestWikiRequestViewer {

	private Config $config;
	private IContextSource $context;
	private PermissionManager $permissionManager;
	private WikiManagerFactory $wikiManagerFactory;
	private WikiRequestManager $wikiRequestManager;

	public function __construct(
		Config $config,
		IContextSource $context,
		PermissionManager $permissionManager,
		WikiManagerFactory $wikiManagerFactory,
		WikiRequestManager $wikiRequestManager
	) {
		$this->config = $config;
		$this->context = $context;
		$this->permissionManager = $permissionManager;
		$this->wikiManagerFactory = $wikiManagerFactory;
		$this->wikiRequestManager = $wikiRequestManager;
	}

	public function getFormDescriptor(): array {
		$user = $this->context->getUser();

		$visibilityConds = [
			0 => 'public',
			1 => 'createwiki-deleterequest',
			2 => 'createwiki-suppressrequest',
		];

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
			if ( !$this->permissionManager->userHasRight( $user, $visibilityConds[$this->wikiRequestManager->getVisibility()] ) ) {
				$this->context->getOutput()->addHTML(
					Html::errorBox( $this->context->msg( 'requestwiki-unknown' )->escaped() )
				);

				return [];
			}
		}

		$formDescriptor = [
			'sitename' => [
				'label-message' => 'requestwikiqueue-request-label-sitename',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => $this->wikiRequestManager->sitename,
			],
			'url' => [
				'label-message' => 'requestwikiqueue-request-label-url',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => $this->wikiRequestManager->url,
			],
			'language' => [
				'label-message' => 'requestwikiqueue-request-label-language',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => $this->wikiRequestManager->language,
			],
			'requester' => [
				'label-message' => 'requestwikiqueue-request-label-requester',
				'type' => 'info',
				'section' => 'request',
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
				'section' => 'request',
				'default' => $this->context->getLanguage()->timeanddate(
					$this->wikiRequestManager->timestamp, true
				),
			],
			'status' => [
				'label-message' => 'requestwikiqueue-request-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => $this->context->msg(
					'requestwikiqueue-' . $this->wikiRequestManager->getStatus()
				)->text(),
			],
			'description' => [
				'type' => 'textarea',
				'rows' => 8,
				'readonly' => true,
				'label-message' => 'requestwikiqueue-request-header-description',
				'section' => 'request',
				'default' => $this->wikiRequestManager->description,
				'raw' => true,
			],
		];

		foreach ( $this->wikiRequestManager->getComments() as $comment ) {
			$formDescriptor['comment' . $comment['timestamp'] ] = [
				'type' => 'textarea',
				'readonly' => true,
				'section' => 'comments',
				'rows' => 8,
				'label-message' => [
					'requestwikiqueue-request-header-wikicreatorcomment-withtimestamp',
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
					'rows' => 8,
					'label-message' => 'requestwikiqueue-request-label-comment',
					'section' => 'comments',
				],
				'submit-comment' => [
					'type' => 'submit',
					'default' => $this->context->msg( 'htmlform-submit' )->text(),
					'section' => 'comments',
				],
				'edit-sitename' => [
					'label-message' => 'requestwikiqueue-request-label-sitename',
					'type' => 'text',
					'section' => 'edit',
					'required' => true,
					'default' => $this->wikiRequestManager->sitename,
				],
				'edit-url' => [
					'label-message' => 'requestwikiqueue-request-label-url',
					'type' => 'text',
					'section' => 'edit',
					'required' => true,
					'default' => $this->wikiRequestManager->url,
					'validation-callback' => [ $this, 'isValidSubdomain' ],
				],
				'edit-language' => [
					'label-message' => 'requestwikiqueue-request-label-language',
					'type' => 'language',
					'default' => $this->wikiRequestManager->language,
					'cssclass' => 'createwiki-infuse',
					'section' => 'edit',
				],
				'edit-description' => [
					'label-message' => 'requestwikiqueue-request-header-requestercomment',
					'type' => 'textarea',
					'section' => 'edit',
					'rows' => 8,
					'required' => true,
					'default' => $this->wikiRequestManager->description,
					'raw' => true,
				],
			];

			if ( $this->config->get( 'CreateWikiCategories' ) ) {
				$formDescriptor['edit-category'] = [
					'type' => 'select',
					'label-message' => 'createwiki-label-category',
					'options' => $this->config->get( 'CreateWikiCategories' ),
					'default' => $this->wikiRequestManager->category,
					'cssclass' => 'createwiki-infuse',
					'section' => 'edit',
				];
			}

			if ( $this->config->get( 'CreateWikiUsePrivateWikis' ) ) {
				$formDescriptor['edit-private'] = [
					'type' => 'check',
					'label-message' => 'requestwiki-label-private',
					'default' => $this->wikiRequestManager->private,
					'section' => 'edit',
				];
			}

			if ( $this->config->get( 'CreateWikiShowBiographicalOption' ) ) {
				$formDescriptor['edit-bio'] = [
					'type' => 'check',
					'label-message' => 'requestwiki-label-bio',
					'default' => $this->wikiRequestManager->bio,
					'section' => 'edit',
				];
			}

			if ( $this->config->get( 'CreateWikiPurposes' ) ) {
				$formDescriptor['edit-purpose'] = [
					'type' => 'select',
					'label-message' => 'requestwiki-label-purpose',
					'options' => $this->config->get( 'CreateWikiPurposes' ),
					'default' => trim( $this->wikiRequestManager->purpose ),
					'cssclass' => 'createwiki-infuse',
					'section' => 'edit',
				];
			}

			$formDescriptor['submit-edit'] = [
				'type' => 'submit',
				'buttonlabel-message' => 'requestwikiqueue-request-label-edit-wiki',
				'section' => 'edit',
			];
		}

		// TODO: Should we really require (createwiki) to suppress wiki requests?
		if ( $this->permissionManager->userHasRight( $user, 'createwiki' ) && !$user->getBlock() ) {

			// You can't even get to this part in suppressed wiki requests without the appropiate userright, so it is OK for the undelete/unsuppress option to be here
			$visibilityOptions = [
				0 => $this->context->msg( 'requestwikiqueue-request-label-visibility-all' )->escaped(),
			];

			if ( $this->permissionManager->userHasRight( $user, 'createwiki-deleterequest' ) ) {
				$visibilityOptions[1] = $this->context->msg( 'requestwikiqueue-request-label-visibility-delete' )->escaped();
			}

			if ( $this->permissionManager->userHasRight( $user, 'createwiki-suppressrequest' ) ) {
				$visibilityOptions[2] = $this->context->msg( 'requestwikiqueue-request-label-visibility-suppress' )->escaped();
			}

			$wm = $this->wikiManagerFactory->newInstance( $this->wikiRequestManager->dbname );
			$wmError = $wm->checkDatabaseName( $this->wikiRequestManager->dbname, forRename: false );

			if ( $wmError ) {
				$this->context->getOutput()->addHTML( Html::errorBox( $wmError ) );
			}

			$formDescriptor += [
				'info-submission' => [
					'type' => 'info',
					'default' => $this->context->msg( 'requestwikiqueue-request-info-submission' )->text(),
					'section' => 'handle',
				],
				'submission-action' => [
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
					'section' => 'handle',
					],
				'reason' => [
					'label-message' => 'createwiki-label-statuschangecomment',
					'cssclass' => 'createwiki-infuse',
					'section' => 'handle',
				],
				'visibility' => [
					'type' => 'check',
					'label-message' => 'revdelete-legend',
					'default' => ( $this->wikiRequestManager->getVisibility() != 0 ) ? 1 : 0,
					'cssclass' => 'createwiki-infuse',
					'section' => 'handle',
				],
				'visibility-options' => [
					'type' => 'radio',
					'label-message' => 'revdelete-suppress-text',
					'hide-if' => [ '!==', 'wpvisibility', '1' ],
					'options' => array_flip( $visibilityOptions ),
					'default' => (string)$this->wikiRequestManager->getVisibility(),
					'cssclass' => 'createwiki-infuse',
					'section' => 'handle',
				],
				'submit-handle' => [
					'type' => 'submit',
					'buttonlabel-message' => 'htmlform-submit',
					'section' => 'handle',
				],
			];

			if ( $this->config->get( 'CreateWikiCannedResponses' ) ) {
				$formDescriptor['reason']['type'] = 'selectorother';
				$formDescriptor['reason']['options'] = $this->config->get( 'CreateWikiCannedResponses' );

				$formDescriptor['reason']['default'] = HTMLFormField::flattenOptions(
					$this->config->get( 'CreateWikiCannedResponses' )
				)[0];
			} else {
				$formDescriptor['reason']['type'] = 'textarea';
				$formDescriptor['reason']['rows'] = 4;
			}

			if ( $wmError ) {
				// We don't want to be able to approve it if the database is not valid
				unset( $formDescriptor['submission-action']['options-messages']['requestwikiqueue-approve'] );
			}
		}

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
	): bool {
		$out = $form->getContext()->getOutput();
		$session = $form->getRequest()->getSession();
		$user = $form->getUser();

		if ( !$user->isRegistered() ) {
			$out->addHTML(
				Html::warningBox(
					Html::rawElement(
						'p',
						[],
						$this->context->msg( 'exception-nologin-text' )->parse()
					),
					'mw-notify-error'
				)
			);

			return false;
		} elseif ( isset( $formData['submit-comment'] ) ) {
			if ( $session->get( 'previous_posted_comment' ) !== $formData['comment'] ) {
				$session->set( 'previous_posted_comment', $formData['comment'] );
				$this->wikiRequestManager->addComment( $formData['comment'], $user );
			} else {
				$out->addHTML( Html::errorBox( $this->context->msg( 'createwiki-duplicate-comment' )->escaped() ) );
				return false;
			}
		} elseif ( isset( $formData['submit-edit'] ) ) {
			$session->remove( 'previous_posted_comment' );

			$this->wikiRequestManager->sitename = $formData['edit-sitename'];
			$this->wikiRequestManager->language = $formData['edit-language'];
			$this->wikiRequestManager->purpose = $formData['edit-purpose'] ?? '';
			$this->wikiRequestManager->description = $formData['edit-description'];
			$this->wikiRequestManager->category = $formData['edit-category'] ?? '';
			$this->wikiRequestManager->private = $formData['edit-private'] ?? 0;
			$this->wikiRequestManager->bio = $formData['edit-bio'] ?? 0;

			$this->wikiRequestManager->reopen( $form->getUser() );
		} elseif ( isset( $formData['submit-handle'] ) ) {
			$session->remove( 'previous_posted_comment' );
			if ( isset( $formData['visibility-options'] ) ) {
				$this->wikiRequestManager->suppress( $user, $formData['visibility-options'] );
			}

			if ( $formData['submission-action'] == 'approve' ) {
				$this->wikiRequestManager->approve( $user, $formData['reason'] );
			} elseif ( $formData['submission-action'] == 'onhold' ) {
				$this->wikiRequestManager->onhold( $formData['reason'], $user );
			} elseif ( $formData['submission-action'] == 'moredetails' ) {
				$this->wikiRequestManager->moredetails( $formData['reason'], $user );
			} else {
				$this->wikiRequestManager->decline( $formData['reason'], $user );
			}
		}

		$out->addHTML(
			Html::successBox(
				Html::element(
					'p',
					[],
					$this->context->msg( 'requestwiki-edit-success' )->plain()
				),
				'mw-notify-success'
			)
		);

		return false;
	}

	public function isValidSubdomain( ?string $subdomain ): bool|Message {
		if ( !$subdomain || ctype_space( $subdomain ) ) {
			return $this->context->msg( 'htmlform-required' );
		}

		$subdomain = strtolower( $subdomain );
		$configSubdomain = $this->config->get( 'CreateWikiSubdomain' );

		if ( strpos( $subdomain, $configSubdomain ) !== false ) {
			$subdomain = str_replace( '.' . $configSubdomain, '', $subdomain );
		}

		$disallowedSubdomains = CreateWikiRegexConstraint::regexFromArrayOrString(
			$this->config->get( 'CreateWikiDisallowedSubdomains' ), '/^(', ')+$/',
			'CreateWikiDisallowedSubdomains'
		);

		$database = $subdomain . $this->config->get( 'CreateWikiDatabaseSuffix' );

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
}
