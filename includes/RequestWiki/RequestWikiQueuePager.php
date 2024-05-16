<?php

namespace Miraheze\CreateWiki\RequestWiki;

use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;
use MediaWiki\Pager\TablePager;
use MediaWiki\Title\Title;

class RequestWikiQueuePager extends TablePager {

	/** @var Config */
	private $config;

	/** @var string */
	private $requester;

	/** @var string */
	private $dbname;

	/** @var string */
	private $status;

	public function __construct( $page, $requester, $dbname, $status ) {
		parent::__construct( $page->getContext(), $page->getLinkRenderer() );

		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );
		$this->mDb = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->getMainLB( $this->config->get( 'CreateWikiGlobalWiki' ) )->getConnection( DB_REPLICA, [], $this->config->get( 'CreateWikiGlobalWiki' ) );
		$this->requester = $requester;
		$this->dbname = $dbname;
		$this->status = $status;
	}

	public function getFieldNames() {
		static $headers = null;

		$headers = [
			'cw_timestamp' => 'requestwikiqueue-request-label-requested-date',
			'cw_dbname' => 'createwiki-label-dbname',
			'cw_sitename' => 'requestwikiqueue-request-label-sitename',
			'cw_user' => 'requestwikiqueue-request-label-requester',
			'cw_language' => 'requestwikiqueue-request-label-language',
			'cw_url' => 'requestwikiqueue-request-label-url',
			'cw_status' => 'requestwikiqueue-request-label-status',
		];

		foreach ( $headers as &$msg ) {
			$msg = $this->msg( $msg )->text();
		}

		return $headers;
	}

	public function formatValue( $name, $value ) {
		$row = $this->mCurrentRow;

		$userFactory = MediaWikiServices::getInstance()->getUserFactory();

		$language = $this->getLanguage();

		switch ( $name ) {
			case 'cw_timestamp':
				$formatted = $language->timeanddate( $row->cw_timestamp );
				break;
			case 'cw_dbname':
				$formatted = $row->cw_dbname;
				break;
			case 'cw_sitename':
				$formatted = $row->cw_sitename;
				break;
			case 'cw_user':
				$formatted = $userFactory->newFromId( $row->cw_user )->getName();
				break;
			case 'cw_url':
				$formatted = $row->cw_url;
				break;
			case 'cw_status':
				$formatted = MediaWikiServices::getInstance()->getLinkRenderer()->makeLink( Title::newFromText( "Special:RequestWikiQueue/{$row->cw_id}" ), $row->cw_status );
				break;
			case 'cw_language':
				$formatted = $row->cw_language;
				break;
			default:
				$formatted = "Unable to format $name";
				break;
		}

		return $formatted;
	}

	public function getQueryInfo() {
		$user = $this->getUser();

		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		$userFactory = MediaWikiServices::getInstance()->getUserFactory();

		$visibility = $permissionManager->userHasRight( $user, 'createwiki' ) ? 1 : 0;

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
			$info['conds']['cw_user'] = $userFactory->newFromName( $this->requester )->getId();
		}

		if ( $this->status && $this->status != '*' ) {
			$info['conds']['cw_status'] = $this->status;
		} elseif ( !$this->status ) {
			$info['conds']['cw_status'] = 'inreview';
		}

		return $info;
	}

	public function getDefaultSort() {
		return 'cw_id';
	}

	public function isFieldSortable( $name ) {
		return true;
	}
}
