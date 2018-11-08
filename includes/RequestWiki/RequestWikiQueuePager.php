<?php

use MediaWiki\MediaWikiServices;

class RequestWikiQueuePager extends TablePager {
	function __construct( $requester, $dbname, $status ) {
		$this->mDb = self::getCreateWikiGlobalWiki();
		$this->requester = $requester;
		$this->dbname = $dbname;
		$this->status = $status;
		parent::__construct( $this->getContext() );
	}

	static function getCreateWikiGlobalWiki() {
		global $wgCreateWikiGlobalWiki;

		$factory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$lb = $factory->getMainLB( $wgCreateWikiGlobalWiki );

		return $lb->getConnectionRef( DB_REPLICA, 'cw_requests', $wgCreateWikiGlobalWiki );
	}

	function getFieldNames() {
		static $headers = null;

		$headers = [
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

	function formatValue( $name, $value ) {
		$row = $this->mCurrentRow;

		switch ( $name ) {
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
				$formatted = Linker::link( Title::newFromText( "Special:RequestWikiQueue/{$row->cw_id}" ), $row->cw_status );
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

	function getQueryInfo() {
		$info = [
			'tables' => [
				'cw_requests'
			],
			'fields' => [
				'cw_id',
				'cw_dbname',
				'cw_language',
				'cw_user',
				'cw_status',
				'cw_url',
				'cw_sitename'
			],
			'conds' => [],
			'joins_conds' => [],
		];

		if ( $this->sitename ) {
			$info['conds']['cw_sitename'] = $this->sitename;
		}

		if ( $this->requester ) {
			$info['conds']['cw_user'] = $this->requester;
		}

		if ( $this->status && $this->status != '*' ) {
			$info['conds']['cw_status'] = $this->status;
		}

		return $info;
	}

	function getDefaultSort() {
		return 'cw_id';
	}

	function isFieldSortable( $name ) {
		return true;
	}
}
