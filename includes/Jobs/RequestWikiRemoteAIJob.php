<?php

namespace Miraheze\CreateWiki\Jobs;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Job;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\User\User;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class RequestWikiRemoteAIJob extends Job {

	public const JOB_NAME = 'RequestWikiRemoteAIJob';

	private Config $config;
	private CreateWikiHookRunner $hookRunner;
	private WikiRequestManager $wikiRequestManager;
	private Client $httpClient;
	private LoggerInterface $logger;
	private string $baseApiUrl;
	private string $apiKey;

	private int $id;
	private string $reason;

	public function __construct(
		array $params,
		ConfigFactory $configFactory,
		CreateWikiHookRunner $hookRunner,
		WikiRequestManager $wikiRequestManager
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->config = $configFactory->makeConfig( 'CreateWiki' );
		$this->hookRunner = $hookRunner;
		$this->wikiRequestManager = $wikiRequestManager;
		$this->logger = LoggerFactory::getInstance( 'CreateWiki' );

		$proxy = $this->config->get( 'HTTPProxy' );

		$guzzleOptions = [
			'base_uri' => 'https://api.openai.com/',
		];

		if ( !$proxy ) {
			$guzzleOptions['proxy'] = $proxy;
		}

		$this->httpClient = new Client( $guzzleOptions );

		$this->baseApiUrl = 'https://api.openai.com/v1';
		$this->apiKey = $this->config->get( ConfigNames::OpenAIConfig )['apikey'];

		$this->id = $params['id'];
		$this->reason = $params['reason'];
	}

	public function run(): bool {
		$this->wikiRequestManager->loadFromID( $this->id );
		$this->logger->debug( 'Loaded request ' . $this->id . ' for AI approval.' );

		// Check if auto approval can be done
		if ( $this->canAutoApprove() ) {
			// Make API request to ChatGPT's Assistant API
			$this->logger->debug( 'Began query to OpenAI for request ' . $this->id );
			$apiResponse = $this->queryChatGPT( $this->reason );

			if ( $apiResponse ) {
				$outcome = $apiResponse['recommendation']['outcome'] ?? 'reject';
				$comment = $apiResponse['recommendation']['public_comment'];

				$this->logger->debug( 'AI outcome for ' . $this->id . ' was ' . $outcome );

				if ( $this->config->get( ConfigNames::OpenAIConfig )['dryrun'] ) {
					if ( $outcome === 'approve' ) {
						$this->wikiRequestManager->addComment(
							comment: rtrim( 'This is an experimental AI analysis. Wiki requesters can safely ignore this.<br /><br />\'\'\'Recommendation\'\'\': Approve.<br /><br />\'\'\'Reasoning\'\'\': ' . $comment ),
							user: User::newSystemUser( 'CreateWiki AI' ),
							log: false,
							type: 'comment',
							// Use all involved users
							notifyUsers: []
						);
					} elseif ( $outcome === 'revise' ) {
						$this->wikiRequestManager->addComment(
							comment: rtrim( 'This is an experimental AI analysis. Wiki requesters can safely ignore this.<br /><br />\'\'\'Recommendation\'\'\': Revise.<br /><br />\'\'\'Reasoning\'\'\': ' . $comment ),
							user: User::newSystemUser( 'CreateWiki AI' ),
							log: false,
							type: 'comment',
							// Use all involved users
							notifyUsers: []
						);
					} elseif ( $outcome === 'decline' ) {
						$this->wikiRequestManager->addComment(
							comment: rtrim( 'This is an experimental AI analysis. Wiki requesters can safely ignore this.<br /><br />\'\'\'Recommendation\'\'\': Decline.<br /><br />\'\'\'Reasoning\'\'\': ' . $comment ),
							user: User::newSystemUser( 'CreateWiki AI' ),
							log: false,
							type: 'comment',
							// Use all involved users
							notifyUsers: []
						);
					} elseif ( $outcome === 'manualreview' ) {
						$this->wikiRequestManager->addComment(
							comment: rtrim( 'This is an experimental AI analysis. Wiki requesters can safely ignore this.<br /><br />\'\'\'Recommendation\'\'\': Manual review required.<br /><br />\'\'\'Reasoning\'\'\': ' . $comment ),
							user: User::newSystemUser( 'CreateWiki AI' ),
							log: false,
							type: 'comment',
							// Use all involved users
							notifyUsers: []
						);
					} else {
						$this->wikiRequestManager->addComment(
							comment: rtrim( 'This is an experimental AI analysis. Wiki requesters can safely ignore this.<br /><br />\'\'\'Recommendation\'\'\': Unknown.<br /><br />\'\'\'Reasoning\'\'\': Something went wrong. Check logs and try again.' ),
							user: User::newSystemUser( 'CreateWiki AI' ),
							log: false,
							type: 'comment',
							// Use all involved users
							notifyUsers: []
						);
					}
				} else {
					if ( $outcome === 'approve' ) {
						// Start query builder so that it can set the status
						$this->wikiRequestManager->startQueryBuilder();

						$this->wikiRequestManager->approve(
							user: User::newSystemUser( 'CreateWiki AI' ),
							comment: 'Request automatically approved with the following reasoning: ' . $comment
						);

						// Execute query builder to commit the status change
						$this->wikiRequestManager->tryExecuteQueryBuilder();

						$this->logger->debug( 'Wiki request ' . $this->id . ' automatically approved by AI decision!\n\nReasoning: ' . $comment );
					} elseif ( $outcome === 'revise' ) {
						$this->wikiRequestManager->startQueryBuilder();

						$this->wikiRequestManager->moredetails(
							user: User::newSystemUser( 'CreateWiki AI' ),
							comment: 'This wiki request requires more details. Here are some more details: ' . $comment
						);

						$this->wikiRequestManager->tryExecuteQueryBuilder();

						$this->logger->debug( 'Wiki request ' . $this->id . ' needs more details.\n\nReasoning: ' . $comment );
					} elseif ( $outcome === 'decline' ) {
						$this->wikiRequestManager->startQueryBuilder();

						$this->wikiRequestManager->decline(
							user: User::newSystemUser( 'CreateWiki AI' ),
							comment: 'We couldn\'t approve your request at this time for the following reason: ' . $comment
						);

						$this->wikiRequestManager->tryExecuteQueryBuilder();

						$this->logger->debug( 'Wiki request' . $this->id . 'rejected by AI decision.\n\nReasoning: ' . $comment );
					} else {
						$this->wikiRequestManager->addComment(
							comment: rtrim( 'This request could not be automatically approved. Your request has been queued for manual review.' ),
							user: User::newSystemUser( 'CreateWiki AI' ),
							log: false,
							type: 'comment',
							// Use all involved users
							notifyUsers: []
						);

						$this->logger->debug( 'Wiki request ' . $this->id . ' could not be approved. Check logs for details.' );
					}
				}
			}
		} else {
			$this->logger->debug( 'Wiki request ' . $this->id . ' was not auto evaluated because it hit the auto approval denylist.' );
		}

		return true;
	}

	private function queryChatGPT( string $reason ): ?array {
		try {
			$sanitizedReason = trim( str_replace( [ "\r\n", "\r" ], "\n", $reason ) );

			// Step 1: Create a new thread
			$threadResponse = $this->createRequest( "/v1/threads", 'POST', [
				'json' => [ "messages" => [ [ "role" => "user", "content" => $sanitizedReason ] ] ],
			] );

			$threadData = json_decode( $threadResponse->getBody()->getContents(), true );
			$threadId = $threadData['id'] ?? null;

			$this->logger->debug( 'Stage 1 for AI decision: Created thread.' );

			$this->logger->debug( 'OpenAI returned for stage 1: ' . json_encode( $threadData ) );

			if ( !$threadId ) {
				$this->logger->error( 'OpenAI did not return a threadId! Instead returned: ' . json_encode( $threadData ) );
				return null;
			}

			// Step 2: Run the message
			$runResponse = $this->createRequest( "/v1/threads/$threadId/runs", 'POST', [
				'json' => [ "assistant_id" => $this->config->get( ConfigNames::OpenAIConfig )['assistant'] ],
			] );

			$runData = json_decode( $runResponse->getBody()->getContents(), true );
			$runId = $runData['id'] ?? null;

			$this->logger->debug( 'Stage 2 for AI decision: Message ran.' );

			$this->logger->debug( 'OpenAI returned for stage 2: ' . json_encode( $runData ) );

			if ( !$runId ) {
				$this->logger->error( 'OpenAI did not return a runId. Instead returned: ' . json_encode( $runData ) );
				return null;
			}

			// Step 3: Poll the status of the run
			$status = 'running';

			$this->logger->debug( 'Stage 3 for AI decision: Polling status...' );

			while ( $status === 'running' ) {
				sleep( 5 );
				$this->logger->debug( 'Sleeping for 5 seconds...' );

				$statusResponse = $this->createRequest( "/v1/threads/$threadId/runs/$runId" );
				$statusData = json_decode( $statusResponse->getBody()->getContents(), true );
				$status = $statusData['status'] ?? 'failed';
				$this->logger->debug( 'Stage 3 for AI decision: Retrieved run status for ' . $runId );

				$this->logger->debug( 'OpenAI returned for stage 3: ' . json_encode( $statusData ) );

				if ( $status === 'in_progress' ) {
					$status = 'running';
				} elseif ( $status === 'failed' ) {
					$this->logger->error( 'Run ' . $runId . ' failed! OpenAI returned: ' . json_encode( $statusData ) );
					return null;
				}
			}

			// Step 4: Query for messages in the thread
			$messagesResponse = $this->createRequest( "/v1/threads/$threadId/messages" );
			$messagesData = json_decode( $messagesResponse->getBody()->getContents(), true ) ?? '';

			$this->logger->debug( 'Stage 4 for AI decision: Queried for messages in ' . $threadId );

			$this->logger->debug( 'OpenAI returned for stage 4: ' . json_encode( $messagesData ) );

			$finalResponseContent = $messagesData['data'][0]['content'][0]['text']['value'] ?? '';
			return json_decode( $finalResponseContent, true );
		} catch ( RequestException $e ) {
			$this->logger->error( 'HTTP request failed: ' . $e->getMessage() );
			return null;
		}
	}

	private function createRequest( string $endpoint, string $method = 'GET', array $options = [] ): ?ResponseInterface {
		$url = $this->baseApiUrl . $endpoint;

		// Set default headers and merge with any additional options
		$options['headers'] = array_merge( [
			'Authorization' => 'Bearer ' . $this->apiKey,
			'Content-Type'  => 'application/json',
			'OpenAI-Beta'   => 'assistants=v2',
		], $options['headers'] ?? [] );

		return $this->httpClient->request( $method, $endpoint, $options );
	}

	private function canAutoApprove(): bool {
		$filter = CreateWikiRegexConstraint::regexFromArray(
			$this->config->get( ConfigNames::AutoApprovalFilter ), '/(', ')+/',
			ConfigNames::AutoApprovalFilter
		);

		$this->logger->debug( 'Checking ' . $this->id . ' against the auto approval filter...' );

		if ( preg_match( $filter, strtolower( $this->reason ) ) ) {
			$this->logger->debug( $this->id . ' matched against the auto approval filter! Manual review is required' );
			return false;
		}

		$this->logger->debug( $this->id . ' passed auto approval filter rveiew' );

		return true;
	}
}
