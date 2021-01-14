<?php

class RequestWikiAIJob extends Job {
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'RequestWikiAIJob', $params );
	}

	public function run() {
		$wr = new WikiRequest( $this->params['id'] );
		$wr->tryAutoCreate( false );
		return true;
	}
}
