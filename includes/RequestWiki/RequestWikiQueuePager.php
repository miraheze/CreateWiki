<?php

use MediaWiki\MediaWikiServices;

class RequestWikiQueuePager extends TablePager {
	private $config;
	private $requester;
	private $dbname;
	private $status;

	public function __construct( $requester, $dbname, $status ) {
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$this->mDb = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->getMainLB( $this->config->get( 'CreateWikiGlobalWiki' ) )->getConnectionRef( DB_REPLICA, [], $this->config->get( 'CreateWikiGlobalWiki' ) );
		$this->requester = $requester;
		$this->dbname = $dbname;
		$this->status = $status;
		parent::__construct( $this->getContext() );
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
			'cw_status' => 'requestwikiqueue-request-label-status'
		];

		foreach ( $headers as &$msg ) {
			$msg = $this->msg( $msg )->text();
		}

		return $headers;
	}

	public function formatValue( $name, $value ) {
		$row = $this->mCurrentRow;

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
				$formatted = User::newFromId( $row->cw_user )->getName();
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
		$visibility = $permissionManager->userHasRight( $user, 'createwiki' ) ? 1 : 0;

		$info = [
			'tables' => [
				'cw_requests'
			],
			'fields' => [
				'cw_id',
				'cw_timestamp',
				'cw_dbname',
				'cw_language',
				'cw_user',
				'cw_status',
				'cw_url',
				'cw_sitename'
			],
			'conds' => [
				'cw_visibility <= ' . $visibility
			],
			'joins_conds' => [],
		];

		if ( $this->dbname ) {
			$info['conds']['cw_dbname'] = $this->dbname;
		}

		if ( $this->requester ) {
			$info['conds']['cw_user'] = User::newFromName( $this->requester )->getId();
		}

		if ( $this->status && $this->status != '*' ) {
			$info['conds']['cw_status'] = $this->status;
		} elseif( !$this->status ) {
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
