<?php

namespace Miraheze\CreateWiki\RequestWiki;

use Config;
use Html;
use HTMLForm;
use HTMLFormField;
use IContextSource;
use Linker;
use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\CreateWikiOOUIForm;
use Miraheze\CreateWiki\WikiManager;
use MWException;

class RequestWikiRequestViewer {

	/** @var Config */
	private $config;

	public function __construct() {
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
	}

	public function getFormDescriptor(
		WikiRequest $request,
		IContextSource $context
	) {
		$visibilityConds = [
			0 => 'read',
			1 => 'createwiki',
			2 => 'delete',
			3 => 'suppressrevision',
		];

		// Gets user from request
		$userR = $context->getUser();

		// if request isn't found, it doesn't exist
		// but if we can't view the request, it also doesn't exist
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();

		if ( !$permissionManager->userHasRight( $userR, $visibilityConds[$request->visibility] ) ) {
			$context->getOutput()->addHTML( Html::errorBox( wfMessage( 'requestwiki-unknown' )->escaped() ) );

			return [];
		}

		$status = ( $request->getStatus() === 'inreview' ) ? 'In review' : ucfirst( $request->getStatus() );

		$formDescriptor = [
			'sitename' => [
				'label-message' => 'requestwikiqueue-request-label-sitename',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => (string)$request->sitename,
			],
			'url' => [
				'label-message' => 'requestwikiqueue-request-label-url',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => (string)$request->url,
			],
			'language' => [
				'label-message' => 'requestwikiqueue-request-label-language',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => (string)$request->language,
			],
			'requester' => [
				// @phan-suppress-next-line SecurityCheck-XSS
				'label-message' => 'requestwikiqueue-request-label-requester',
				'type' => 'info',
				'section' => 'request',
				'default' => $request->requester->getName() . Linker::userToolLinks( $request->requester->getId(), $request->requester->getName() ),
				'raw' => true,
			],
			'requestedDate' => [
				'label-message' => 'requestwikiqueue-request-label-requested-date',
				'type' => 'info',
				'section' => 'request',
				'default' => $context->getLanguage()->timeanddate( $request->timestamp, true ),
			],
			'status' => [
				'label-message' => 'requestwikiqueue-request-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => (string)$status,
			],
			'description' => [
				'type' => 'textarea',
				'rows' => 4,
				'readonly' => true,
				'label-message' => 'requestwikiqueue-request-header-requestercomment',
				'section' => 'request',
				'default' => (string)$request->description,
				'raw' => true,
			],
		];

		foreach ( $request->getComments() as $comment ) {
			$formDescriptor['comment' . $comment['timestamp'] ] = [
				'type' => 'textarea',
				'readonly' => true,
				'section' => 'comments',
				'rows' => 4,
				// @phan-suppress-next-line SecurityCheck-XSS
				'label' => wfMessage( 'requestwikiqueue-request-header-wikicreatorcomment-withtimestamp' )->rawParams( $comment['user']->getName() )->params( $context->getLanguage()->timeanddate( $comment['timestamp'], true ) )->text(),
				'default' => $comment['comment'],
			];
		}

		if ( $permissionManager->userHasRight( $userR, 'createwiki' ) || $userR->getId() == $request->requester->getId() ) {
			$formDescriptor += [
				'comment' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'requestwikiqueue-request-label-comment',
					'section' => 'comments',
				],
				'submit-comment' => [
					'type' => 'submit',
					'default' => wfMessage( 'htmlform-submit' )->text(),
					'section' => 'comments',
				],
				'edit-sitename' => [
					'label-message' => 'requestwikiqueue-request-label-sitename',
					'type' => 'text',
					'section' => 'edit',
					'required' => true,
					'default' => (string)$request->sitename,
				],
				'edit-url' => [
					'label-message' => 'requestwikiqueue-request-label-url',
					'type' => 'text',
					'section' => 'edit',
					'required' => true,
					'default' => (string)$request->url,
				],
				'edit-language' => [
					'label-message' => 'requestwikiqueue-request-label-language',
					'type' => 'language',
					'default' => (string)$request->language,
					'cssclass' => 'createwiki-infuse',
					'section' => 'edit',
				],
				'edit-description' => [
					'label-message' => 'requestwikiqueue-request-header-requestercomment',
					'type' => 'textarea',
					'section' => 'edit',
					'rows' => 4,
					'required' => true,
					'default' => (string)$request->description,
					'raw' => true,
				],
			];

			if ( $this->config->get( 'CreateWikiCategories' ) ) {
				$formDescriptor['edit-category'] = [
					'type' => 'select',
					'label-message' => 'createwiki-label-category',
					'options' => $this->config->get( 'CreateWikiCategories' ),
					'default' => (string)$request->category,
					'cssclass' => 'createwiki-infuse',
					'section' => 'edit',
				];
			}

			if ( $this->config->get( 'CreateWikiUsePrivateWikis' ) ) {
				$formDescriptor['edit-private'] = [
					'type' => 'check',
					'label-message' => 'requestwiki-label-private',
					'default' => $request->private,
					'section' => 'edit',
				];
			}

			if ( $this->config->get( 'CreateWikiShowBiographicalOption' ) ) {
				$formDescriptor['edit-bio'] = [
					'type' => 'check',
					'label-message' => 'requestwiki-label-bio',
					'default' => $request->bio,
					'section' => 'edit',
				];
			}

			if ( $this->config->get( 'CreateWikiPurposes' ) ) {
				$formDescriptor['edit-purpose'] = [
					'type' => 'select',
					'label-message' => 'requestwiki-label-purpose',
					'options' => $this->config->get( 'CreateWikiPurposes' ),
					'default' => trim( $request->purpose ),
					'cssclass' => 'createwiki-infuse',
					'section' => 'edit',
				];
			}

			$formDescriptor['submit-edit'] = [
				'type' => 'submit',
				'default' => wfMessage( 'requestwikiqueue-request-label-edit-wiki' )->text(),
				'section' => 'edit',
			];
		}

		if ( $permissionManager->userHasRight( $userR, 'createwiki' ) ) {
			$visibilityOptions = [
				0 => wfMessage( 'requestwikiqueue-request-label-visibility-all' )->text(),
				1 => wfMessage( 'requestwikiqueue-request-label-visibility-hide' )->text(),
			];

			if ( $permissionManager->userHasRight( $userR, 'delete' ) ) {
				$visibilityOptions[2] = wfMessage( 'requestwikiqueue-request-label-visibility-delete' )->text();
			}

			if ( $permissionManager->userHasRight( $userR, 'suppressrevision' ) ) {
				$visibilityOptions[3] = wfMessage( 'requestwikiqueue-request-label-visibility-oversight' )->text();
			}

			// @phan-suppress-next-line SecurityCheck-PathTraversal
			$wm = new WikiManager( $request->dbname );

			$wmError = $wm->checkDatabaseName( $request->dbname );

			$formDescriptor += [
				'info-submission' => [
					'type' => 'info',
					'default' => wfMessage( 'requestwikiqueue-request-info-submission' )->text(),
					'section' => 'handle',
				],
				'submission-action' => [
					'type' => 'select',
					'label-message' => 'requestwikiqueue-request-label-action',
					'options' => [
						wfMessage( 'requestwikiqueue-approve' )->text() => 'approve',
						wfMessage( 'requestwikiqueue-decline' )->text() => 'decline',
					],
					'default' => $request->getStatus(),
					'cssclass' => 'createwiki-infuse',
					'section' => 'handle',
				],
				'visibility' => [
					'type' => 'select',
					'label-message' => 'requestwikiqueue-request-label-visibility',
					'options' => array_flip( $visibilityOptions ),
					'default' => $request->visibility,
					'cssclass' => 'createwiki-infuse',
					'section' => 'handle',
				],
				'reason' => [
					'label-message' => 'createwiki-label-reason',
					'cssclass' => 'createwiki-infuse',
					'section' => 'handle',
				],
				'submit-handle' => [
					'type' => 'submit',
					'default' => wfMessage( 'htmlform-submit' )->text(),
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
				$formDescriptor['submit-error-info'] = [
					'type' => 'info',
					'section' => 'handle',
					'default' => $wmError,
					'raw' => true,
				];

				$formDescriptor['submission-action']['default'] = 'decline';
				$formDescriptor['submission-action']['disabled'] = true;
			}
		}

		return $formDescriptor;
	}

	/**
	 * @param string $id
	 * @param IContextSource $context
	 * @param string $formClass
	 */
	public function getForm(
		string $id,
		IContextSource $context,
		$formClass = CreateWikiOOUIForm::class
	) {
		$context->getOutput()->addModules( 'ext.createwiki.oouiform' );

		try {
			$request = new WikiRequest( (int)$id );
		} catch ( MWException $e ) {
			$context->getOutput()->addHTML( Html::errorBox( wfMessage( 'requestwiki-unknown' )->escaped() ) );

			return;
		}

		$formDescriptor = $this->getFormDescriptor( $request, $context );

		$htmlForm = new $formClass( $formDescriptor, $context, 'requestwikiqueue' );

		$htmlForm->setId( 'mw-baseform-requestviewer' );
		$htmlForm->suppressDefaultSubmit();
		$htmlForm->setSubmitCallback(
			function ( array $formData, HTMLForm $form ) use ( $request ) {
				return $this->submitForm( $formData, $form, $request );
			}
		);

		return $htmlForm;
	}

	protected function submitForm(
		array $formData,
		HTMLForm $form,
		WikiRequest $request
	) {
		$out = $form->getContext()->getOutput();

		if ( isset( $formData['submit-comment'] ) ) {
			$request->addComment( $formData['comment'], $form->getUser() );
		} elseif ( isset( $formData['submit-edit'] ) ) {
			$subdomain = $formData['edit-url'];
			$err = '';
			$status = $request->parseSubdomain( $subdomain, $err );

			if ( $status === false ) {
				if ( $err !== '' ) {
					$out->addHTML( Html::errorBox( wfMessage( 'createwiki-error-' . $err )->parse() ) );
				}

				return false;
			}

			$request->sitename = $formData['edit-sitename'];
			$request->language = $formData['edit-language'];
			$request->purpose = $formData['edit-purpose'];
			$request->description = $formData['edit-description'];
			$request->category = $formData['edit-category'];
			$request->private = $formData['edit-private'];
			$request->bio = $formData['edit-bio'];
			$request->reopen( $form->getUser() );
		} elseif ( isset( $formData['submit-handle'] ) ) {
			$request->visibility = $formData['visibility'];

			if ( $formData['submission-action'] == 'approve' ) {
				$request->approve( $form->getUser(), $formData['reason'] );
			} else {
				$request->decline( $formData['reason'], $form->getUser() );
			}
		}

		$out->addHTML( Html::successBox( wfMessage( 'requestwiki-edit-success' )->escaped() ) );

		return true;
	}
}
