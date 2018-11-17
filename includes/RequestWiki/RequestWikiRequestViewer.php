<?php

class RequestWikiRequestViewer {
	public function getFormDescriptor(
		int $requestid = NULL,
		IContextSource $context
	) {
		global $wgUser, $wgCreateWikiGlobalWiki, $wgCreateWikiUsePrivateWikis;

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

		$visibility = ( $res ) ? (int)$res->cw_visibility : 0;

		// if request isn't found, it doesn't exist
		// but if we can't view the request, it also doesn't exist
		if (
			!$res
			|| $visibility === 1 && !$wgUser->isAllowed( 'createwiki' )
			|| $visibility === 2 && !$wgUser->isAllowed( 'delete' )
			|| $visibility === 3 && !$wgUser->isAllowed( 'suppressrevision' )
		) {
			$context->getOutput()->addWikiMsg( 'requestwikiqueue-requestnotfound' );
			return false;
		}

		$status = ( $res->cw_status === 'inreview' ) ? 'In review' : ucfirst( $res->cw_status );

		$url = ( $res->cw_custom  != '' ) ? $res->cw_custom : $res->cw_url;

		$user = User::newFromId( $res->cw_user );

		$creationParams = [
			'cwRequester' => $user->getName(),
			'cwLanguage' => $res->cw_language,
			'cwSitename' => $res->cw_sitename,
			'cwCategory' => $res->cw_category,
			'cwDBname' => $res->cw_dbname
		];

		if ( $res->cw_private != 0 && $wgCreateWikiUsePrivateWikis ) {
			$creationParams['cwPrivate'] = 1;
		}

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
				'raw' => true
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

		if ( $wgUser->isAllowed( 'createwiki' ) || $context->getUser()->getId() == $res->cw_user ) {
			$formDescriptor['edit'] = array(
				'type' => 'submit',
				'section' => 'request',
				'default' => wfMessage( 'requestwikiqueue-request-label-edit-wiki' )->text()
			);
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

		if ( $wgUser->isAllowed( 'createwiki' ) ) {
			$visibilityoptions = [
				0 => wfMessage( 'requestwikiqueue-request-label-visibility-all' )->text(),
				1 => wfMessage( 'requestwikiqueue-request-label-visibility-hide' )->text()
			];

			if ( $wgUser->isAllowed( 'delete' ) ) {
				$visibilityoptions[2] = wfMessage( 'requestwikiqueue-request-label-visibility-delete' )->text();
			}

			if ( $wgUser->isAllowed( 'suppressrevision' ) ) {
				$visibilityoptions[3] = wfMessage( 'requestwikiqueue-request-label-visibility-oversight' )->text();
			}

			$formDescriptor += [
				'createwiki' => [
					'type' => 'info',
					'label' => '',
					'section' => 'handling',
					'default' => Linker::link( Title::newFromText( 'Special:CreateWiki' ), wfMessage( 'requestwikiqueue-request-label-create-wiki' )->text(), [], $creationParams ),
					'raw' => true
				],
				'action' => [
					'type' => 'radio',
					'vertical-label' => false,
					'label-message' => 'requestwikiqueue-request-label-action',
					'options' => [
						wfMessage( 'requestwikiqueue-request-label-inreview' )->text() => 'inreview',
						wfMessage( 'requestwikiqueue-request-label-approved' )->text() => 'approved',
						wfMessage( 'requestwikiqueue-request-label-declined' )->text() => 'declined'
					],
					'section' => 'handling'
				],
				'visibility' => [
					'type' => 'radio',
					'label-message' => 'requestwikiqueue-request-label-visibility',
					'options' => array_flip( $visibilityoptions ),
					'section' => 'handling'
				],
				'comment' => [
					'type' => 'text',
					'label-message' => 'requestwikiqueue-request-label-comment',
					'section' => 'handling'
				],
				'submit' => [
					'type' => 'submit',
					'default' => wfMessage( 'htmlform-submit' )->text(),
					'section' => 'handling'
				]
			];
		}

		return $formDescriptor;
	}


	public function getForm(
		string $requestid = NULL,
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
		int $requestid = NULL
	) {
		global $wgCreateWikiGlobalWiki;

		if ( isset( $formData['edit'] ) && $formData['edit'] ) {
			header( 'Location: ' . SpecialPage::getTitleFor( 'RequestWikiEdit' )->getFullUrl() . '/' . $requestid );
			return;
		}


		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiGlobalWiki );

		$dbw->update( 'cw_requests',
			[
				'cw_status' => $formData['action'],
				'cw_visibility' => $formData['visibility']
			],
			[
				'cw_id' => $requestid
			],
			__METHOD__
		);

		$dbw->insert( 'cw_comments',
			[
				'cw_id' => $requestid,
				'cw_comment' => $formData['comment'],
				'cw_comment_timestamp' => $dbw->timestamp(),
				'cw_comment_user' => $form->getContext()->getUser()->getId()
			],
			__METHOD__
		);

		$form->getContext()->getOutput()->addHTML( '<div class="successbox">' . wfMessage( 'requestwiki-edit-success', $requestid )->escaped() . '</div>' );

		return true;
	}
}
