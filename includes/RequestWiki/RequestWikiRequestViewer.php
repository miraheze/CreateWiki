<?php

namespace Miraheze\CreateWiki\RequestWiki;

use Exception;
use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\HTMLForm\HTMLFormField;
use MediaWiki\Linker\Linker;
use MediaWiki\Permissions\PermissionManager;
use Miraheze\CreateWiki\CreateWikiOOUIForm;
use Miraheze\CreateWiki\Services\WikiManagerFactory;

class RequestWikiRequestViewer {

	private Config $config;
	private IContextSource $context;
	private PermissionManager $permissionManager;
	private WikiManagerFactory $wikiManagerFactory;
	private WikiRequest $request;

	public function __construct(
		Config $config,
		IContextSource $context,
		PermissionManager $permissionManager,
		WikiManagerFactory $wikiManagerFactory
	) {
		$this->config = $config;
		$this->context = $context;
		$this->permissionManager = $permissionManager;
		$this->wikiManagerFactory = $wikiManagerFactory;
	}

	public function getFormDescriptor() {
		$visibilityConds = [
			0 => 'public',
			1 => 'createwiki-deleterequest',
			2 => 'createwiki-suppressrequest',
		];

		// Gets user from request
		$userR = $this->context->getUser();

		// if request isn't found, it doesn't exist
		// but if we can't view the request, it also doesn't exist

		// T12010: 3 is a legacy suppression level, treat it as a suppressed request hidden from everyone
		if ( $this->request->getVisibility() >= 3 ) {
			$this->context->getOutput()->addHTML(
				Html::errorBox(
					$this->context->msg( 'requestwiki-unknown' )->escaped()
				)
			);

			return [];
		}

		if ( $visibilityConds[$request->getVisibility()] !== 'public' ) {
			if ( !$this->permissionManager->userHasRight( $userR, $visibilityConds[$this->request->getVisibility()] ) ) {
				$this->context->getOutput()->addHTML(
					Html::errorBox(
						$this->context->msg( 'requestwiki-unknown' )->escaped()
					)
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
				'default' => $this->request->sitename,
			],
			'url' => [
				'label-message' => 'requestwikiqueue-request-label-url',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => $this->request->url,
			],
			'language' => [
				'label-message' => 'requestwikiqueue-request-label-language',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => $this->request->language,
			],
			'requester' => [
				// @phan-suppress-next-line SecurityCheck-XSS
				'label-message' => 'requestwikiqueue-request-label-requester',
				'type' => 'info',
				'section' => 'request',
				'default' => $this->request->requester->getName() . Linker::userToolLinks( $this->request->requester->getId(), $this->request->requester->getName() ),
				'raw' => true,
			],
			'requestedDate' => [
				'label-message' => 'requestwikiqueue-request-label-requested-date',
				'type' => 'info',
				'section' => 'request',
				'default' => $this->context->getLanguage()->timeanddate( (string)$this->request->timestamp, true ),
			],
			'status' => [
				'label-message' => 'requestwikiqueue-request-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => $this->context->msg( 'requestwikiqueue-' . $this->request->getStatus() )->text(),
			],
			'description' => [
				'type' => 'textarea',
				'rows' => 8,
				'readonly' => true,
				'label-message' => 'requestwikiqueue-request-header-description',
				'section' => 'request',
				'default' => $this->request->description,
				'raw' => true,
			],
		];

		foreach ( $this->request->getComments() as $comment ) {
			$formDescriptor['comment' . $comment['timestamp'] ] = [
				'type' => 'textarea',
				'readonly' => true,
				'section' => 'comments',
				'rows' => 8,
				'label' => $this->context->msg( 'requestwikiqueue-request-header-wikicreatorcomment-withtimestamp' )->rawParams( $comment['user']->getName() )->params( $this->context->getLanguage()->timeanddate( $comment['timestamp'], true ) )->text(),
				'default' => $comment['comment'],
			];
		}

		if (
			( $this->permissionManager->userHasRight( $userR, 'createwiki' ) ||
			$userR->getId() == $this->request->requester->getId() ) &&
			!$userR->getBlock()
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
					'default' => $this->request->sitename,
				],
				'edit-url' => [
					'label-message' => 'requestwikiqueue-request-label-url',
					'type' => 'text',
					'section' => 'edit',
					'required' => true,
					'default' => $this->request->url,
					'validation-callback' => [ $request, 'parseSubdomain' ],
				],
				'edit-language' => [
					'label-message' => 'requestwikiqueue-request-label-language',
					'type' => 'language',
					'default' => $this->request->language,
					'cssclass' => 'createwiki-infuse',
					'section' => 'edit',
				],
				'edit-description' => [
					'label-message' => 'requestwikiqueue-request-header-requestercomment',
					'type' => 'textarea',
					'section' => 'edit',
					'rows' => 8,
					'required' => true,
					'default' => $this->request->description,
					'raw' => true,
				],
			];

			if ( $this->config->get( 'CreateWikiCategories' ) ) {
				$formDescriptor['edit-category'] = [
					'type' => 'select',
					'label-message' => 'createwiki-label-category',
					'options' => $this->config->get( 'CreateWikiCategories' ),
					'default' => $this->request->category,
					'cssclass' => 'createwiki-infuse',
					'section' => 'edit',
				];
			}

			if ( $this->config->get( 'CreateWikiUsePrivateWikis' ) ) {
				$formDescriptor['edit-private'] = [
					'type' => 'check',
					'label-message' => 'requestwiki-label-private',
					'default' => $this->request->private,
					'section' => 'edit',
				];
			}

			if ( $this->config->get( 'CreateWikiShowBiographicalOption' ) ) {
				$formDescriptor['edit-bio'] = [
					'type' => 'check',
					'label-message' => 'requestwiki-label-bio',
					'default' => $this->request->bio,
					'section' => 'edit',
				];
			}

			if ( $this->config->get( 'CreateWikiPurposes' ) ) {
				$formDescriptor['edit-purpose'] = [
					'type' => 'select',
					'label-message' => 'requestwiki-label-purpose',
					'options' => $this->config->get( 'CreateWikiPurposes' ),
					'default' => trim( $this->request->purpose ),
					'cssclass' => 'createwiki-infuse',
					'section' => 'edit',
				];
			}

			$formDescriptor['submit-edit'] = [
				'type' => 'submit',
				'default' => $this->context->msg( 'requestwikiqueue-request-label-edit-wiki' )->text(),
				'section' => 'edit',
			];
		}

		// TODO: Should we really require (createwiki) to suppress wiki requests?
		if ( $this->permissionManager->userHasRight( $userR, 'createwiki' ) && !$userR->getBlock() ) {

			// You can't even get to this part in suppressed wiki requests without the appropiate userright, so it is OK for the undelete/unsuppress option to be here
			$visibilityOptions = [
				0 => $this->context->msg( 'requestwikiqueue-request-label-visibility-all' )->escaped(),
			];

			if ( $permissionManager->userHasRight( $userR, 'createwiki-deleterequest' ) ) {
				$visibilityOptions[1] = $this->context->msg( 'requestwikiqueue-request-label-visibility-delete' )->escaped();
			}

			if ( $permissionManager->userHasRight( $userR, 'createwiki-suppressrequest' ) ) {
				$visibilityOptions[2] = $this->context->msg( 'requestwikiqueue-request-label-visibility-suppress' )->escaped();
			}

			$wm = $this->wikiManagerFactory->newInstance( $this->request->dbname );
			$wmError = $wm->checkDatabaseName( $this->request->dbname, forRename: false );

			if ( $wmError ) {
				$context->getOutput()->addHTML( Html::errorBox( $wmError ) );
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
					'default' => $this->request->getStatus(),
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
					'default' => ( $this->request->getVisibility() != 0 ) ? 1 : 0,
					'cssclass' => 'createwiki-infuse',
					'section' => 'handle',
				],
				'visibility-options' => [
					'type' => 'radio',
					'label-message' => 'revdelete-suppress-text',
					'hide-if' => [ '!==', 'wpvisibility', '1' ],
					'options' => array_flip( $visibilityOptions ),
					'default' => (string)$this->request->getVisibility(),
					'cssclass' => 'createwiki-infuse',
					'section' => 'handle',
				],
				'submit-handle' => [
					'type' => 'submit',
					'default' => $this->context->msg( 'htmlform-submit' )->text(),
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
	 * @param string $id
	 * @param IContextSource $context
	 * @param string $formClass
	 */
	public function getForm( int $requestID ): ?CreateWikiOOUIForm {
		$out = $this->context->getOutput();

		try {
			$this->request = new WikiRequest( $id );
		} catch ( Exception $e ) {
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
	) {
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
				$this->request->addComment( $formData['comment'], $user );
			} else {
				$out->addHTML( Html::errorBox( $this->context->msg( 'createwiki-duplicate-comment' )->escaped() ) );
				return false;
			}
		} elseif ( isset( $formData['submit-edit'] ) ) {
			$session->remove( 'previous_posted_comment' );

			$this->request->sitename = $formData['edit-sitename'];
			$this->request->language = $formData['edit-language'];
			$this->request->purpose = $formData['edit-purpose'] ?? '';
			$this->request->description = $formData['edit-description'];
			$this->request->category = $formData['edit-category'] ?? '';
			$this->request->private = $formData['edit-private'] ?? 0;
			$this->request->bio = $formData['edit-bio'] ?? 0;

			$this->request->reopen( $form->getUser() );
		} elseif ( isset( $formData['submit-handle'] ) ) {
			$session->remove( 'previous_posted_comment' );
			if ( isset( $formData['visibility-options'] ) ) {
				$this->request->suppress( $user, $formData['visibility-options'] );
			}

			if ( $formData['submission-action'] == 'approve' ) {
				$this->request->approve( $user, $formData['reason'] );
			} elseif ( $formData['submission-action'] == 'onhold' ) {
				$this->request->onhold( $formData['reason'], $user );
			} elseif ( $formData['submission-action'] == 'moredetails' ) {
				$this->request->moredetails( $formData['reason'], $user );
			} else {
				$this->request->decline( $formData['reason'], $user );
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
}
