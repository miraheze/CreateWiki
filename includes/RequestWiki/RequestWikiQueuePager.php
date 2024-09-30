<?php

namespace Miraheze\CreateWiki\RequestWiki;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Pager\TablePager;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\UserFactory;
use Miraheze\CreateWiki\ConfigNames;
use Wikimedia\Rdbms\IConnectionProvider;

class RequestWikiQueuePager extends TablePager {

	private LinkRenderer $linkRenderer;
	private PermissionManager $permissionManager;
	private UserFactory $userFactory;

	private string $dbname;
	private string $requester;
	private string $status;

	public function __construct(
		Config $config,
		IContextSource $context,
		IConnectionProvider $connectionProvider,
		LinkRenderer $linkRenderer,
		PermissionManager $permissionManager,
		UserFactory $userFactory,
		string $dbname,
		string $requester,
		string $status
	) {
		parent::__construct( $context, $linkRenderer );

		$this->mDb = $connectionProvider->getReplicaDatabase(
			$config->get( ConfigNames::GlobalWiki )
		);

		$this->linkRenderer = $linkRenderer;
		$this->permissionManager = $permissionManager;
		$this->userFactory = $userFactory;

		$this->dbname = $dbname;
		$this->requester = $requester;
		$this->status = $status;
	}

	/** @inheritDoc */
	public function getFieldNames(): array {
		return [
			'cw_timestamp' => $this->msg( 'requestwikiqueue-request-label-requested-date' )->text(),
			'cw_dbname' => $this->msg( 'createwiki-label-dbname' )->text(),
			'cw_sitename' => $this->msg( 'requestwikiqueue-request-label-sitename' )->text(),
			'cw_user' => $this->msg( 'requestwikiqueue-request-label-requester' )->text(),
			'cw_language' => $this->msg( 'requestwikiqueue-request-label-language' )->text(),
			'cw_url' => $this->msg( 'requestwikiqueue-request-label-url' )->text(),
			'cw_status' => $this->msg( 'requestwikiqueue-request-label-status' )->text(),
		];
	}

	/** @inheritDoc */
	public function formatValue( $name, $value ): string {
		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'cw_timestamp':
				$language = $this->getLanguage();
				$formatted = $language->timeanddate( $row->cw_timestamp, true );
				break;
			case 'cw_dbname':
				$formatted = $row->cw_dbname;
				break;
			case 'cw_sitename':
				$formatted = $row->cw_sitename;
				break;
			case 'cw_user':
				$formatted = $this->userFactory->newFromId( $row->cw_user )->getName();
				break;
			case 'cw_url':
				$formatted = $row->cw_url;
				break;
			case 'cw_status':
				$formatted = $this->linkRenderer->makeLink(
					SpecialPage::getTitleValueFor( 'RequestWikiQueue', $row->cw_id ),
					$row->cw_status
				);
				break;
			case 'cw_language':
				$formatted = $row->cw_language;
				break;
			default:
				$formatted = "Unable to format $name";
		}

		return $formatted;
	}

	/** @inheritDoc */
	public function getQueryInfo(): array {
		$user = $this->getUser();

		$visibility = $this->permissionManager->userHasRight( $user, 'createwiki' ) ? 1 : 0;

		$info = [
			'tables' => [
				'cw_requests',
			],
			'fields' => [
				'cw_id',
				'cw_timestamp',
				'cw_dbname',
				'cw_language',
				'cw_user',
				'cw_status',
				'cw_url',
				'cw_sitename',
			],
			'conds' => [
				'cw_visibility <= ' . $visibility,
			],
			'joins_conds' => [],
		];

		if ( $this->dbname ) {
			$info['conds']['cw_dbname'] = $this->dbname;
		}

		if ( $this->requester ) {
			$info['conds']['cw_user'] = $this->userFactory->newFromName( $this->requester )->getId();
		}

		if ( $this->status && $this->status != '*' ) {
			$info['conds']['cw_status'] = $this->status;
		} elseif ( !$this->status ) {
			$info['conds']['cw_status'] = 'inreview';
		}

		return $info;
	}

	/** @inheritDoc */
	public function getDefaultSort(): string {
		return 'cw_id';
	}

	/** @inheritDoc */
	public function isFieldSortable( $name ): bool {
		return $name !== 'cw_user';
	}
}
