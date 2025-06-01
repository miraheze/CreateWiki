<?php

namespace Miraheze\CreateWiki;

use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\OOUIHTMLForm;
use MediaWiki\Logger\LoggerFactory;
use OOUI\FieldsetLayout;
use OOUI\HtmlSnippet;
use OOUI\IndexLayout;
use OOUI\PanelLayout;
use OOUI\TabPanelLayout;
use OOUI\Widget;
use function is_array;

class CreateWikiOOUIForm extends OOUIHTMLForm {

	/** @var bool Override default value from HTMLForm */
	protected $mSubSectionBeforeFields = false;

	/**
	 * @param string $html
	 * @return string
	 */
	public function wrapForm( $html ) {
		$html = Html::rawElement( 'div', [ 'id' => 'createwiki' ], $html );
		return parent::wrapForm( $html );
	}

	/**
	 * @param string $legend
	 * @param string $section
	 * @param array $attributes
	 * @param bool $isRoot
	 * @return PanelLayout
	 */
	protected function wrapFieldSetSection( $legend, $section, $attributes, $isRoot ) {
		$layout = parent::wrapFieldSetSection( $legend, $section, $attributes, $isRoot );

		$layout->addClasses( [ 'createwiki-fieldset-wrapper' ] );
		$layout->removeClasses( [ 'oo-ui-panelLayout-framed' ] );

		return $layout;
	}

	/**
	 * @return string
	 */
	public function getBody() {
		$tabPanels = [];
		foreach ( $this->mFieldTree as $key => $val ) {
			if ( !is_array( $val ) ) {
				LoggerFactory::getInstance( 'CreateWiki' )->debug(
					'Encountered a field not attached to a section: {key}',
					[ 'key' => $key ]
				);
				continue;
			}

			$label = $this->getLegend( $key );

			$content =
				$this->getHeaderHtml( $key ) .
				$this->displaySection(
					$val,
					'',
					"mw-section-$key-"
				) .
				$this->getFooterHtml( $key );

			$tabPanels[] = new TabPanelLayout( "mw-section-$key", [
				'classes' => [ 'mw-htmlform-autoinfuse-lazy' ],
				'label' => $label,
				'content' => new FieldsetLayout( [
					'classes' => [ 'createwiki-section-fieldset' ],
					'id' => "mw-section-$key",
					'label' => $label,
					'items' => [
						new Widget( [
							'content' => new HtmlSnippet( $content ),
						] ),
					],
				] ),
				'expanded' => false,
				'framed' => true,
			] );
		}

		$indexLayout = new IndexLayout( [
			'infusable' => true,
			'expanded' => false,
			'autoFocus' => false,
			'classes' => [ 'createwiki-tabs' ],
		] );

		$indexLayout->addTabPanels( $tabPanels );

		$header = $this->formatFormHeader();

		$form = new PanelLayout( [
			'framed' => true,
			'expanded' => false,
			'classes' => [ 'createwiki-tabs-wrapper' ],
			'content' => $indexLayout,
		] );

		return $header . $form;
	}
}
