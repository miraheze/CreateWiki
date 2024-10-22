<?php

namespace Miraheze\CreateWiki\RequestWiki;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Linker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Pager\TablePager;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Wikimedia\Rdbms\IConnectionProvider;

class FlaggedRequestsPager extends TablePager {

	private LinkRenderer $linkRenderer;
	private PermissionManager $permissionManager;
	private UserFactory $userFactory;
	private WikiManagerFactory $wikiManagerFactory;
	private WikiRequestManager $wikiRequestManager;

	public function __construct(
		Config $config,
		IContextSource $context,
		IConnectionProvider $connectionProvider,
		LinkRenderer $linkRenderer,
		PermissionManager $permissionManager,
		UserFactory $userFactory,
		WikiManagerFactory $wikiManagerFactory,
		WikiRequestManager $wikiRequestManager
	) {
		parent::__construct( $context, $linkRenderer );

		$this->mDb = $connectionProvider->getReplicaDatabase(
			$config->get( ConfigNames::GlobalWiki )
		);

		$this->linkRenderer = $linkRenderer;
		$this->permissionManager = $permissionManager;
		$this->userFactory = $userFactory;
		$this->wikiManagerFactory = $wikiManagerFactory;
		$this->wikiRequestManager = $wikiRequestManager;
	}

	/** @inheritDoc */
	public function getFieldNames(): array {
		return [
			'cw_flag_timestamp' => $this->msg( 'createwiki-flaggedrequests-label-timestamp' )->text(),
			'cw_id' => $this->msg( 'createwiki-flaggedrequests-label-request' )->text(),
			'cw_flag_reason' => $this->msg( 'createwiki-flaggedrequests-label-reason' )->text(),
			'cw_flag_actor' => $this->msg( 'createwiki-flaggedrequests-label-actor' )->text(),
			'cw_flag_dbname' => $this->msg( 'createwiki-label-dbname' )->text(),
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
				$formatted = $this->linkRenderer->makeLink(
					SpecialPage::getTitleValueFor( 'RequestWikiQueue', $row->cw_id ),
					"#{$row->cw_id}"
				);
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
				$wikiManager = $this->wikiManagerFactory->newInstance( $row->cw_id );
				if ( !$wikiManager->exists() ) {
					$this->wikiRequestManager->loadFromID( $row->cw_id );
					// TODO: when we require 1.43, use LinkRenderer::makeExternalLink
					$formatted = Html::element( 'a', [
						'href' => '//' . $this->wikiRequestManager->getUrl()
					], $this->wikiRequestManager->getUrl() );
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
		$user = $this->getUser();

		$visibility = $this->permissionManager->userHasRight( $user, 'createwiki' ) ? 1 : 0;

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
				'cw_flag_visibility <= ' . $visibility,
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
