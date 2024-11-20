<?php

namespace Miraheze\CreateWiki\RequestWiki;

use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Linker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Pager\TablePager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Wikimedia\Rdbms\IConnectionProvider;

class FlaggedWikisPager extends TablePager {

	private LinkRenderer $linkRenderer;
	private UserFactory $userFactory;
	private WikiManagerFactory $wikiManagerFactory;

	public function __construct(
		IContextSource $context,
		IConnectionProvider $connectionProvider,
		LinkRenderer $linkRenderer,
		UserFactory $userFactory,
		WikiManagerFactory $wikiManagerFactory
	) {
		parent::__construct( $context, $linkRenderer );

		$this->mDb = $connectionProvider->getReplicaDatabase( 'virtual-createwiki-central' );

		$this->linkRenderer = $linkRenderer;
		$this->userFactory = $userFactory;
		$this->wikiManagerFactory = $wikiManagerFactory;
	}

	/** @inheritDoc */
	public function getFieldNames(): array {
		return [
			'cw_flag_timestamp' => $this->msg( 'createwiki-flaggedwikis-label-timestamp' )->text(),
			'cw_id' => $this->msg( 'createwiki-flaggedwikis-label-request' )->text(),
			'cw_flag_dbname' => $this->msg( 'createwiki-flaggedwikis-label-wiki' )->text(),
			'cw_flag_actor' => $this->msg( 'createwiki-flaggedwikis-label-actor' )->text(),
			'cw_flag_reason' => $this->msg( 'createwiki-flaggedwikis-label-reason' )->text(),
		];
	}

	/** @inheritDoc */
	public function formatValue( $name, $value ): string {
		$row = $this->getCurrentRow();

		switch ( $name ) {
			case 'cw_flag_timestamp':
				$formatted = $this->escape( $this->getLanguage()->userTimeAndDate(
					$row->cw_flag_timestamp, $this->getUser()
				) );
				break;
			case 'cw_id':
				if ( $row->cw_id > 0 ) {
					$formatted = $this->linkRenderer->makeLink(
						SpecialPage::getTitleValueFor( 'RequestWikiQueue', $row->cw_id ),
						"#{$row->cw_id}"
					);
					break;
				}

				$formatted = '-';
				break;
			case 'cw_flag_reason':
				$formatted = $this->escape( $row->cw_flag_reason );
				break;
			case 'cw_flag_actor':
				$formatted = Linker::userLink(
					$this->userFactory->newFromActorId( $row->cw_flag_actor )->getId(),
					$this->userFactory->newFromActorId( $row->cw_flag_actor )->getName()
				);
				break;
			case 'cw_flag_dbname':
				$wikiManager = $this->wikiManagerFactory->newInstance( $row->cw_flag_dbname );
				if ( $wikiManager->exists() ) {
					// TODO: when we require 1.43, use LinkRenderer::makeExternalLink
					$formatted = Html::element( 'a', [
						'href' => $wikiManager->getUrl()
					], $wikiManager->getUrl() );
					break;
				}

				$formatted = $this->escape( $row->cw_flag_dbname );
				break;
			default:
				$formatted = $this->escape( "Unable to format {$name}" );
		}

		return $formatted;
	}

	/**
	 * Safely HTML-escapes $value
	 */
	private function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8', false );
	}

	/** @inheritDoc */
	public function getQueryInfo(): array {
		$dbr = $this->getDatabase();

		$info = [
			'tables' => [
				'cw_flags',
			],
			'fields' => [
				'cw_id',
				'cw_flag_actor',
				'cw_flag_dbname',
				'cw_flag_reason',
				'cw_flag_timestamp',
			],
			'conds' => [
				// TODO: all allowed visibilities
				$dbr->expr( 'cw_flag_visibility', '=', 0 ),
				$dbr->expr( 'cw_flag_expiry', '=', 0 )
					->or( 'cw_flag_expiry', '>', $dbr->timestamp() )
			],
			'joins_conds' => [],
		];

		return $info;
	}

	/** @inheritDoc */
	public function getDefaultSort(): string {
		return 'cw_flag_timestamp';
	}

	/** @inheritDoc */
	public function isFieldSortable( $name ): bool {
		return $name !== 'cw_flag_actor';
	}
}
