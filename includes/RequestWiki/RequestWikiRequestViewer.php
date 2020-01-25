<?php

class RequestWikiRequestViewer {
	public function getFormDescriptor(
		int $requestid,
		IContextSource $context
	) {
		global $wgCreateWikiGlobalWiki, $wgCreateWikiUsePrivateWikis;

		OutputPage::setupOOUI(
			strtolower( $context->getSkin()->getSkinName() ),
			$context->getLanguage()->getDir()
		);

		$dbr = wfGetDB( DB_REPLICA, [], $wgCreateWikiGlobalWiki );
		$res = $dbr->selectRow( 'cw_requests',
			[
				'cw_user',
				'cw_comment',
				'cw_dbname',
				'cw_language',
				'cw_private',
				'cw_sitename',
				'cw_status',
				'cw_timestamp',
				'cw_url',
				'cw_custom',
				'cw_category',
				'cw_visibility'
			],
			[
				'cw_id' => $requestid
			],
			__METHOD__
		);

		$visibilityLevel = ( $res ) ? (int)$res->cw_visibility : 0;

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
		$mwService = MediaWikiServices::getInstance()->getPermissionManager();
		if ( !$res || !$mwService->userHasRight( $userR, $visibilityConds[$visibilityLevel] ) ) {
			throw new PermissionsError( $visibilityConds[$visibilityLevel] );
		}

		$status = ( $res->cw_status === 'inreview' ) ? 'In review' : ucfirst( $res->cw_status );

		$url = ( $res->cw_custom  != '' ) ? $res->cw_custom : $res->cw_url;

		$user = User::newFromId( $res->cw_user );

		$formDescriptor = [
			'sitename' => [
				'label-message' => 'requestwikiqueue-request-label-sitename',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => (string)$res->cw_sitename
			],
			'url' => [
				'label-message' => 'requestwikiqueue-request-label-url',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => (string)$url
			],
			'language' => [
				'label-message' => 'requestwikiqueue-request-label-language',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => (string)$res->cw_language
			],
			'requester' => [
				'label-message' => 'requestwikiqueue-request-label-requester',
				'type' => 'info',
				'section' => 'request',
				'default' => $user->getName() . Linker::userToolLinks( $res->cw_user, $user->getName() ),
				'raw' => true,
			],
			'requestedDate' => [
				'label-message' => 'requestwikiqueue-request-label-requested-date',
				'type' => 'info',
				'readonly' => true,
				'section' => 'request',
				'default' => $context->getLanguage()->timeanddate( $res->cw_timestamp, true ),
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
				'default' => (string)$res->cw_comment,
				'raw' => true
			]
		];

		if ( $mwService->userHasRight( $userR, 'createwiki' ) || $userR->getId() == $res->cw_user ) {
			$formDescriptor['edit'] = [
				'type' => 'submit',
				'section' => 'request',
				'default' => wfMessage( 'requestwikiqueue-request-label-edit-wiki' )->text()
			];
		}

		$comments = $dbr->select( 'cw_comments',
			[
				'cw_id',
				'cw_comment',
				'cw_comment_user',
				'cw_comment_timestamp'
			],
			[
				'cw_id' => $requestid
			],
			__METHOD__,
			[
				'ORDER BY' => 'cw_comment_timestamp DESC'
			]
		);

		foreach ( $comments as $comment ) {
			$formDescriptor["comment-$comment->cw_comment_timestamp"] = [
				'type' => 'textarea',
				'readonly' => true,
				'section' => 'comments',
				'rows' => 3,
				'label' => wfMessage( 'requestwikiqueue-request-header-wikicreatorcomment-withtimestamp' )->rawParams( User::newFromId( $comment->cw_comment_user )->getName() )->params( $context->getLanguage()->timeanddate( $comment->cw_comment_timestamp, true ) )->text(),
				'default' => $comment->cw_comment
			];
		}

		if ( $mwService->userHasRight( $userR, 'createwiki' ) ) {
			$visibilityoptions = [
				0 => wfMessage( 'requestwikiqueue-request-label-visibility-all' )->text(),
				1 => wfMessage( 'requestwikiqueue-request-label-visibility-hide' )->text()
			];

			if ( $mwService->userHasRight( $userR, 'delete' ) ) {
				$visibilityoptions[2] = wfMessage( 'requestwikiqueue-request-label-visibility-delete' )->text();
			}

			if ( $mwService->userHasRight( $userR, 'suppressrevision' ) ) {
				$visibilityoptions[3] = wfMessage( 'requestwikiqueue-request-label-visibility-oversight' )->text();
			}

			$formDescriptor += [
				'info-approve' => [
					'type' => 'info',
					'default' => wfMessage( 'requestwikiqueue-request-info-approve' )->text(),
					'section' => 'approve'
				],
				'submit-create' => [
					'type' => 'submit',
					'default' => wfMessage( 'requestwikiqueue-request-label-create-wiki' )->text(),
					'section' => 'approve'
				],
				'info-decline' => [
					'type' => 'info',
					'default' => wfMessage( 'requestwikiqueue-request-info-decline' )->text(),
					'section' => 'decline'
				],
				'visibility' => [
					'type' => 'radio',
					'label-message' => 'requestwikiqueue-request-label-visibility',
					'options' => array_flip( $visibilityoptions ),
					'section' => 'decline'
				],
				'reason' => [
					'type' => 'text',
					'label-message' => 'createwiki-label-reason',
					'section' => 'decline'
				],
				'submit-decline' => [
					'type' => 'submit',
					'default' => wfMessage( 'requestwikiqueue-decline' )->text(),
					'section' => 'decline'
				],
				'comment' => [
					'type' => 'text',
					'label-message' => 'requestwikiqueue-request-label-comment',
					'section' => 'comments'
				],
				'submit-comment' => [
					'type' => 'submit',
					'default' => wfMessage( 'htmlform-submit' )->text(),
					'section' => 'comments'
				]
			];
		}

		return $formDescriptor;
	}


	public function getForm(
		string $requestid,
		IContextSource $context,
		$formClass = CreateWikiOOUIForm::class
	) {
		$formDescriptor = $this->getFormDescriptor( $requestid, $context );

		$htmlForm = new $formClass( $formDescriptor, $context, 'requestwikiqueue' );

		$htmlForm->setId( 'mw-baseform-requestviewer' );
		$htmlForm->suppressDefaultSubmit();
		$htmlForm->setSubmitCallback(
			function ( array $formData, HTMLForm $form ) use ( $requestid ) {
				return $this->submitForm( $formData, $form, $requestid );
			}
		);

		return $htmlForm;
	}

	protected function submitForm(
		array $formData,
		HTMLForm $form,
		int $requestid
	) {
		global $wgCreateWikiGlobalWiki;

		if ( isset( $formData['edit'] ) && $formData['edit'] ) {
			header( 'Location: ' . SpecialPage::getTitleFor( 'RequestWikiEdit' )->getFullUrl() . '/' . $requestid );
			return null;
		}

		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiGlobalWiki );

		$reqRow = $dbw->selectRow(
			'cw_requests',
			'*',
			[
				'cw_id' => $requestid
			]
		);

		if ( isset( $formData['submit-decline'] ) && $formData['submit-decline'] ) {
			$rowsUpdate = [
				'cw_visibility' => $formData['visibility'],
				'cw_status' => 'declined'
			];

			$addCommentToRequest = $formData['reason'];

			$wm = new WikiManager( $reqRow->cw_dbname );

			$requesterId = $reqRow->cw_user;

			$wm->notificationsTrigger( 'request-declined', $reqRow->cw_dbname, [ 'reason' => $formData['reason'], 'id' => $requestid ], User::newFromID( $requesterId )->getName() );
		}

		if ( isset( $formData['submit-create'] ) && $formData['submit-create'] ) {
			$wm = new WikiManager( $reqRow->cw_dbname );

			$requesterUser = User::newFromID( $reqRow->cw_user );
			$actorUser = $form->getContext()->getUser();

			$validName = $wm->checkDatabaseName( $reqRow->cw_dbname );

			$notCreated = $wm->create( $reqRow->cw_sitename, $reqRow->cw_language, $reqRow->cw_private, $reqRow->cw_category, $requesterUser->getName(), $actorUser->getName(), "[[Special:RequestWikiQueue/{$requestid}|Requested]]" );

			if ( $validName || $notCreated ) {
				$error = $notCreated ?? $validName;
				$form->getContext()->getOutput()->addHTML( "<div class=\"errorbox\">{$error}</div>" );
				return true;
			}

			$addCommentToRequest = 'Created.';

			$rowsUpdate = [
				'cw_status' => 'approved'
			];
		}

		if ( isset( $rowsUpdate ) ) {
			$dbw->update( 'cw_requests',
				$rowsUpdate,
				[
					'cw_id' => $requestid
				]
			);
		}

		if ( isset( $formData['submit-comment'] ) && $formData['submit-comment'] ) {
			$dbw->insert( 'cw_comments',
				[
					'cw_id' => $requestid,
					'cw_comment' => $formData['comment'],
					'cw_comment_timestamp' => $dbw->timestamp(),
					'cw_comment_user' => $form->getContext()->getUser()->getId()
				]
			);
		} elseif ( $addCommentToRequest ) {
			$dbw->insert(
				'cw_comments',
				[
					'cw_id' => $requestid,
					'cw_comment' => $addCommentToRequest,
					'cw_comment_timestamp' => $dbw->timestamp(),
					'cw_comment_user' => $form->getContext()->getUser()->getId()
				]
			);
		}

		$form->getContext()->getOutput()->addHTML( '<div class="successbox">' . wfMessage( 'requestwiki-edit-success', $requestid )->escaped() . '</div>' );

		return true;
	}
}
