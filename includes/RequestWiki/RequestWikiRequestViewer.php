<?php

use MediaWiki\MediaWikiServices;

class RequestWikiRequestViewer {
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
			3 => 'suppressrevision'
		];

		// Gets user from request
		$userR = $context->getUser();
		// if request isn't found, it doesn't exist
		// but if we can't view the request, it also doesn't exist
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		if ( !$permissionManager->userHasRight( $userR, $visibilityConds[$request->visibility]) ) {
			$context->getOutput()->addHTML( '<div class="errorbox">' . wfMessage( 'requestwiki-unknown') . '</div>' );
			return [];
		}

		$status = ( $request->getStatus() === 'inreview' ) ? 'In review' : ucfirst( $request->getStatus() );

		$formDescriptor = [
			'sitename' => [
				'label-message' => 'requestwikiqueue-request-label-sitename',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => (string)$request->sitename
			],
			'url' => [
				'label-message' => 'requestwikiqueue-request-label-url',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => (string)$request->url,
				'validation-callback' => function( $url ) { return ctype_alnum( explode( '.', $url, 2 )[0] ); }
			],
			'language' => [
				'label-message' => 'requestwikiqueue-request-label-language',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => (string)$request->language
			],
			'requester' => [
				'label-message' => 'requestwikiqueue-request-label-requester',
				'type' => 'info',
				'section' => 'request',
				'default' => $request->requester->getName() . Linker::userToolLinks( $request->requester->getId(), $request->requester->getName() ),
				'raw' => true,
			],
			'requestedDate' => [
				'label-message' => 'requestwikiqueue-request-label-requested-date',
				'type' => 'info',
				'readonly' => true,
				'section' => 'request',
				'default' => $context->getLanguage()->timeanddate( $request->timestamp, true ),
				'raw' => true,
			],
			'status' => [
				'label-message' => 'requestwikiqueue-request-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => (string)$status
			],
			'description' => [
				'label-message' => 'requestwikiqueue-request-header-requestercomment',
				'type' => 'textarea',
				'readonly' => true,
				'section' => 'request',
				'rows' => 5,
				'default' => (string)$request->description,
				'raw' => true
			]
		];

		foreach ( $request->getComments() as $comment ) {
			$formDescriptor['comment' . $comment['timestamp'] ] = [
				'type' => 'textarea',
				'readonly' => true,
				'section' => 'comments',
				'rows' => 3,
				'label' => wfMessage( 'requestwikiqueue-request-header-wikicreatorcomment-withtimestamp' )->rawParams( $comment['user']->getName() )->params( $context->getLanguage()->timeanddate( $comment['timestamp'], true ) )->text(),
				'default' => $comment['comment']
			];
		}

		if ( $permissionManager->userHasRight( $userR, 'createwiki' ) || $userR->getId() == $request->requester->getId() ) {
			$formDescriptor += [
				'comment' => [
					'type' => 'text',
					'label-message' => 'requestwikiqueue-request-label-comment',
					'section' => 'comments'
				],
				'submit-comment' => [
					'type' => 'submit',
					'default' => wfMessage( 'htmlform-submit' )->text(),
					'section' => 'comments'
				],
				'edit-sitename' => [
					'label-message' => 'requestwikiqueue-request-label-sitename',
					'type' => 'text',
					'section' => 'edit',
					'default' => (string)$request->sitename
				],
				'edit-url' => [
					'label-message' => 'requestwikiqueue-request-label-url',
					'type' => 'text',
					'section' => 'edit',
					'default' => (string)$request->url
				],
				'edit-language' => [
					'label-message' => 'requestwikiqueue-request-label-language',
					'type' => 'text',
					'section' => 'edit',
					'default' => (string)$request->language
				],
				'edit-description' => [
					'label-message' => 'requestwikiqueue-request-header-requestercomment',
					'type' => 'textarea',
					'section' => 'edit',
					'rows' => 5,
					'default' => (string)$request->description,
					'raw' => true
				]
			];

			if ( $this->config->get( 'CreateWikiCategories' ) ) {
				$formDescriptor['edit-category'] = [
					'type' => 'select',
					'label-message' => 'createwiki-label-category',
					'options' => $this->config->get( 'CreateWikiCategories' ),
					'default' => (string)$request->category,
					'section' => 'edit'
				];
			}

			if ( $this->config->get( 'CreateWikiUsePrivateWikis' ) ) {
				$formDescriptor['edit-private'] = [
					'type' => 'check',
					'label-message' => 'requestwiki-label-private',
					'default' => $request->private,
					'section' => 'edit'
				];
			}

			$formDescriptor['submit-edit'] = [
				'type' => 'submit',
				'default' => wfMessage( 'requestwikiqueue-request-label-edit-wiki' )->text(),
				'section' => 'edit'
			];
		}

		if ( $permissionManager->userHasRight( $userR, 'createwiki' ) ) {
			$visibilityOptions = [
				0 => wfMessage( 'requestwikiqueue-request-label-visibility-all' )->text(),
				1 => wfMessage( 'requestwikiqueue-request-label-visibility-hide' )->text()
			];

			if ( $permissionManager->userHasRight( $userR, 'delete' ) ) {
				$visibilityOptions[2] = wfMessage( 'requestwikiqueue-request-label-visibility-delete' )->text();
			}

			if ( $permissionManager->userHasRight( $userR, 'suppressrevision' ) ) {
				$visibilityOptions[3] = wfMessage( 'requestwikiqueue-request-label-visibility-oversight' )->text();
			}

			$wm = new WikiManager( $request->dbname );
			$wmError = $wm->checkDatabaseName( $request->dbname );

			$formDescriptor += [
				'info-submission' => [
					'type' => 'info',
					'default' => wfMessage( 'requestwikiqueue-request-info-submission' )->text(),
					'section' => 'handle'
				],
				'submission-action' => [
					'type' => 'select',
					'label-message' => 'requestwikiqueue-request-label-action',
					'options' => [
						wfMessage( 'requestwikiqueue-approve')->text() => 'approve',
						wfMessage( 'requestwikiqueue-decline')->text() => 'decline'
					],
					'default' => $request->getStatus(),
					'section' => 'handle'
				],
				'visibility' => [
					'type' => 'select',
					'label-message' => 'requestwikiqueue-request-label-visibility',
					'options' => array_flip( $visibilityOptions ),
					'default' => $request->visibility,
					'section' => 'handle'
				],
				'reason' => [
					'type' => 'text',
					'label-message' => 'createwiki-label-reason',
					'section' => 'handle'
				],
				'submit-handle' => [
					'type' => 'submit',
					'default' => wfMessage( 'htmlform-submit' )->text(),
					'disabled' => (bool)$wmError,
					'section' => 'handle'
				]
			];

			if ( $wmError ) {
				$formDescriptor['submit-error-info'] = [
					'type' => 'info',
					'default' => $wmError,
					'section' => 'handle'
				];
			}
		}

		return $formDescriptor;
	}


	public function getForm(
		string $id,
		IContextSource $context,
		$formClass = CreateWikiOOUIForm::class
	) {
		try {
			$request = new WikiRequest( $id );
		} catch ( TypeError $e ) {
			$context->getOutput()->addHTML( '<div class="errorbox">' . wfMessage( 'requestwiki-unknown') . '</div>' );
			return $htmlForm = new $formClass( [], $context, 'requestwikiqueue' );;
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
		if ( isset( $formData['submit-comment'] ) ) {
			$request->addComment( $formData['comment'], $form->getUser() );
		} elseif ( isset( $formData['submit-edit'] ) ) {
			$request->sitename = $formData['edit-sitename'];
			$request->url = $formData['edit-url'];
			$request->language = $formData['edit-language'];
			$request->description = $formData['edit-description'];
			$request->category = $formData['edit-category'];
			$request->private = $formData['edit-private'];
			$request->reopen( $form->getUser() );
		} elseif ( isset( $formData['submit-handle'] ) ) {
			$request->visibility = $formData['visibility'];

			if ( $formData['submission-action'] == 'approve' ) {
				$request->approve( $form->getUser(), $formData['reason'] );
			} else {
				$request->decline( $formData['reason'], $form->getUser() );
			}
		}

		$form->getContext()->getOutput()->addHTML( '<div class="successbox">' . wfMessage( 'requestwiki-edit-success' )->escaped() . '</div>' );

		return true;
	}
}
