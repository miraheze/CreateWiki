<?php

namespace Miraheze\CreateWiki\RequestWiki\FormFields;

use MediaWiki\HTMLForm\Field\HTMLInfoField;
use OOUI\IconWidget;
use OOUI\Tag;

class DetailsWithIconField extends HTMLInfoField {

	private readonly bool $fieldCheck;

	/** @inheritDoc */
	public function __construct( $info ) {
		$this->fieldCheck = $info['fieldCheck'] ?? false;

		$info['cssclass'] = 'mw-htmlform-field-HTMLInfoField';
		$info['default'] = $this->getFieldWithIcon();
		$info['nodata'] = true;
		$info['raw'] = true;

		parent::__construct( $info );
	}

	private function getFieldWithIcon(): string {
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
