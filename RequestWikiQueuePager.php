<?php
class RequestWikiQueuePager extends ReverseChronologicalPager {
	public $searchConds, $specialPage, $y, $m;

	function __construct( $specialPage, $searchConds, $y, $m ) {
		parent::__construct();

		$this->getDateCond( $y, $m );
		$this->searchConds = $searchConds ? $searchConds : array();
		$this->specialPage = $specialPage;
	}

	function formatRow( $row ) {
		$comment = Linker::commentBlock( $row->cw_comment );
		$user = Linker::userLink( $row->cw_user, $row->user_name ) . Linker::userToolLinks( $row->cw_user, $row->user_name );
		$sitename = $row->cw_sitename;
		$status = $row->cw_status;
		$idlink = Linker::link( Title::newFromText( 'Special:RequestWikiQueue/' . $row->cw_id ), "#{$row->cw_id}" );

		return '<li>' .
			$this->getLanguage()->timeanddate(
				wfTimestamp( TS_MW, $row->cw_timestamp ),
				true
			) .
			' ' .
			$this->msg(
				'requestwikiqueue-logpagerentry',
				$user,
				htmlspecialchars( $sitename ),
				$idlink,
				$this->msg( 'requestwikiqueue-pager-status-' . $status )
			)->text() .
			$comment .
			'</li>';
	}

	function getStartBody() {
		if ( $this->getNumRows() ) {
			return '<ul>';
		} else {
			return '';
		}
	}

	function getEndBody() {
		if ( $this->getNumRows() ) {
			return '</ul>';
		} else {
			return '';
		}
	}

	function getEmptyBody() {
		return '<p>' . $this->msg( 'requestwikiqueue-norequests' )->escaped() . '</p>';
	}

	function getIndexField() {
		return 'cw_timestamp';
	}

	function getQueryInfo() {
		$this->searchConds[] = 'user_id = cw_user';
		return array(
			'tables' => array( 'cw_requests', 'user' ),
			'fields' => $this->selectFields(),
			'conds' => $this->searchConds
		);
	}

	function selectFields() {
		return array(
			'cw_id', 'cw_timestamp', 'cw_user', 'cw_sitename',
			'cw_private', 'cw_comment', 'cw_status', 'user_name'
		);
	}
}
