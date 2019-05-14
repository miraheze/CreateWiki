<?php
class SpecialCreateWiki extends FormSpecialPage {
        function __construct() {
                parent::__construct( 'CreateWiki', 'createwiki' );
        }

	protected function getFormFields() {
		global $wgCreateWikiUseCategories, $wgCreateWikiCategories, $wgCreateWikiUsePrivateWikis, $wgCreateWikiCDBDirectory;

		$par = $this->par;
		$request = $this->getRequest();

		// Build language selector
		$languages = Language::fetchLanguageNames( NULL, 'wmfile' );
		ksort( $languages );
		$options = [];
		foreach ( $languages as $code => $name ) {
			$options["{$code} - {$name}"] = $code;
		}

		$formDescriptor = [
			'dbname' => [
				'label-message' => 'createwiki-label-dbname',
				'type' => 'text',
				'default' => $request->getVal( 'cwDBname' ) ? $request->getVal( 'cwDBname' ) : $par,
				'required' => true,
				'validation-callback' => [ __CLASS__, 'validateDBname' ],
				'name' => 'cwDBname',
			],
			'requester' => [
				'label-message' => 'createwiki-label-requester',
				'type' => 'user',
				'default' => $request->getVal( 'cwRequester' ),
				'exists' => true,
				'required' => true,
				'name' => 'cwRequester',
			],
			'sitename' => [
				'label-message' => 'createwiki-label-sitename',
				'type' => 'text',
				'default' => $request->getVal( 'cwSitename' ),
				'size' => 20,
				'name' => 'cwSitename',
			],
			'language' => [
				'type' => 'select',
				'options' => $options,
				'label-message' => 'createwiki-label-language',
				'default' => $request->getVal( 'cwLanguage' ) ? $request->getVal( 'cwLanguage' ) : 'en',
				'name' => 'cwLanguage',
			]
		];

		if ( $wgCreateWikiUsePrivateWikis ) {
			$formDescriptor['private'] = array(
				'type' => 'check',
				'label-message' => 'createwiki-label-private',
				'name' => 'cwPrivate',
			);
		}


		if ( $wgCreateWikiUseCategories && $wgCreateWikiCategories ) {
			$formDescriptor['category'] = array(
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'options' => $wgCreateWikiCategories,
				'name' => 'cwCategory',
				'default' => 'uncategorised',
			);
		}

		$formDescriptor['reason'] = array(
			'label-message' => 'createwiki-label-reason',
			'type' => 'text',
			'default' => $request->getVal( 'wpreason' ),
			'size' => 45,
			'required' => true,
		);

		return $formDescriptor;
	}

	public function onSubmit( array $formData ) {
		global $IP, $wgCreateWikiDatabase, $wgCreateWikiSQLfiles, $wgDBname,
		$wgCreateWikiUseCategories, $wgCreateWikiUsePrivateWikis, $wgCreateWikiUseEchoNotifications,
		$wgCreateWikiEmailNotifications;

		if ( $wgCreateWikiUsePrivateWikis ) {
			$private = $formData['private'];
		} else {
			$private = 0;
		}

		if ( $wgCreateWikiUseCategories ) {
			$category = $formData['category'];
		} else {
			$category = 'uncategorised';
		}

		$wm = new WikiManager( $formData['dbname'] );

		$wm->create( $formData['sitename'], $formData['language'], $private, 0, $category, $formData['requester'], $this->getContext()->getUser()->getName(), $formData['reason'] );

		$this->getOutput()->addHTML( '<div class="successbox">' . wfMessage( 'createwiki-success' )->escaped() . '</div>' );

		return true;
	}

	public function validateDBname( $DBname, $allData ) {
		if ( is_null( $DBname ) ) {
			return true;
		}

		$wm = new WikiManager( $DBname );

		$check = $wm->checkDatabaseName( $DBname );

		if ( $check ) {
			return $check;
		}

		return true;
	}

	public function getDisplayFormat() {
		return 'ooui';
        }

	protected function getGroupName() {
		return 'wikimanage';
	}
}
