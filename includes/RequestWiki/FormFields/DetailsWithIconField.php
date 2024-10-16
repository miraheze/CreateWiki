<?php

namespace Miraheze\CreateWiki\RequestWiki\FormFields;

use MediaWiki\HTMLForm\HTMLFormField;
use OOUI\IconWidget;
use OOUI\Tag;

class DetailsWithIconField extends HTMLFormField {

	private bool $fieldCheck;

	/** @inheritDoc */
	public function __construct( $info ) {
		$this->fieldCheck = $info['fieldCheck'] ?? false;
		parent::__construct( $info );
	}
	
	/** @inheritDoc */
	public function getInputHTML( $value ): string {
		return '';
	}

	/** @inheritDoc */
	public function getInputOOUI( $value ): string {
		if ( $this->fieldCheck ) {
			$icon = new IconWidget( [
				'icon' => 'check',
				'flags' => [ 'success' ],
			] );

			$text = ( new Tag( 'b' ) )->appendContent(
				$this->msg( 'htmlform-yes' )->escaped()
			);
		} else {
			$icon = new IconWidget( [
				'icon' => 'close',
				'flags' => [ 'progressive' ],
			] );

			$text = ( new Tag( 'b' ) )->appendContent(
				$this->msg( 'htmlform-no' )->escaped()
			);
		}

		return $icon . ' ' . $text;
	}
}
