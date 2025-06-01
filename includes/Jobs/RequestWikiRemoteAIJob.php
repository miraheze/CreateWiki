<?php

namespace Miraheze\CreateWiki\Jobs;

use Exception;
use Job;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\User\User;
use MessageLocalizer;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Psr\Log\LoggerInterface;
use function count;
use function htmlspecialchars;
use function json_decode;
use function json_encode;
use function preg_match;
use function sleep;
use function sprintf;
use function str_replace;
use function strtolower;
use function substr;
use function trim;
use const ENT_QUOTES;

class RequestWikiRemoteAIJob extends Job {

	public const JOB_NAME = 'RequestWikiRemoteAIJob';

	private readonly MessageLocalizer $messageLocalizer;

	private readonly string $apiKey;
	private readonly string $baseApiUrl;
	private readonly int $id;

	public function __construct(
		array $params,
		private readonly Config $config,
		private readonly LoggerInterface $logger,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly WikiRequestManager $wikiRequestManager
	) {
		parent::__construct( self::JOB_NAME, $params );
		$this->messageLocalizer = RequestContext::getMain();

		$this->apiKey = $this->config->get( ConfigNames::OpenAIConfig )['apikey'] ?? '';

		$this->baseApiUrl = 'https://api.openai.com/v1';
		$this->id = $params['id'];
	}

	/** @inheritDoc */
	public function run(): bool {
		if ( !$this->config->get( ConfigNames::OpenAIConfig )['apikey'] ) {
			$this->logger->debug( 'OpenAI API key is missing! AI job cannot start.' );
			$this->setLastError( 'OpenAI API key is missing! Cannot query API without it!' );
		} elseif ( !$this->config->get( ConfigNames::OpenAIConfig )['assistantid'] ) {
			$this->logger->debug( 'OpenAI Assistant ID is missing! AI job cannot start.' );
			$this->setLastError( 'OpenAI Assistant ID is missing! Cannot run AI model without an assistant!' );
		}

		$this->wikiRequestManager->loadFromID( $this->id );

		$this->logger->debug(
			'Loaded request {id} for AI approval.',
			[
				'id' => $this->id,
			]
		);

		if ( !$this->canAutoApprove() ) {
			$this->logger->debug(
				'Wiki request {id} was not auto-evaluated! Request matched the denylist.',
				[
					'id' => $this->id,
				]
			);

			return true;
		}

		// Initiate OpenAI query for decision
		$this->logger->debug(
			'Querying OpenAI for decision on wiki request {id}...',
			[
				'id' => $this->id,
			]
		);

		$apiResponse = $this->queryOpenAI(
			$this->wikiRequestManager->isBio(),
			$this->wikiRequestManager->getCategory(),
			$this->wikiRequestManager->getAllExtraData(),
			$this->wikiRequestManager->getLanguage(),
			$this->wikiRequestManager->isPrivate(),
			$this->wikiRequestManager->getReason(),
			$this->wikiRequestManager->getSitename(),
			substr( $this->wikiRequestManager->getDBname(), 0, -4 ),
			$this->wikiRequestManager->getRequester()->getName(),
			count( $this->wikiRequestManager->getVisibleRequestsByUser(
				$this->wikiRequestManager->getRequester(),
				( new UltimateAuthority( User::newSystemUser( 'CreateWiki AI' ) ) )->getUser()
			) )
		);

		if ( !$apiResponse ) {
			$commentText = $this->messageLocalizer->msg( 'requestwiki-ai-error' )
				->inContentLanguage()
				->parse();

			$this->wikiRequestManager->addComment(
				comment: $commentText,
				user: User::newSystemUser( 'CreateWiki AI' ),
				log: false,
				type: 'comment',
				notifyUsers: []
			);

			return true;
		}

		if ( $apiResponse['error'] ) {
			$publicCommentText = $this->messageLocalizer->msg( 'requestwiki-ai-error-reason' )
				->inContentLanguage()
				->parse();

			$requestHistoryComment = $this->messageLocalizer->msg( 'requestwiki-ai-error-history-reason' )
				->params( $apiResponse['error'] )
				->inContentLanguage()
				->parse();

			$this->wikiRequestManager->addRequestHistory(
				action: 'ai-error',
				details: $requestHistoryComment,
				user: User::newSystemUser( 'CreateWiki AI' )
			);

			$this->wikiRequestManager->addComment(
				comment: $publicCommentText,
				user: User::newSystemUser( 'CreateWiki AI' ),
				log: false,
				type: 'comment',
				notifyUsers: []
			);
		}

		// Extract response details with default fallbacks
		$confidence = (int)( $apiResponse['recommendation']['confidence'] ?? 0 );
		$outcome = $apiResponse['recommendation']['outcome'] ?? 'unknown';
		$comment = $apiResponse['recommendation']['public_comment'] ?? 'No comment provided. Please check logs.';

		$this->logger->debug(
			'AI decision for wiki request {id} was {outcome} (with {confidence}% confidence) with reasoning: {comment}',
			[
				'comment' => $comment,
				'confidence' => $confidence,
				'id' => $this->id,
				'outcome' => $outcome,
			]
		);

		if ( $this->config->get( ConfigNames::OpenAIConfig )['dryrun'] ) {
			return $this->handleDryRun( $outcome, $comment, $confidence );
		}

		return $this->handleLiveRun( $outcome, $comment, $confidence );
	}

	private function handleDryRun(
		string $outcome,
		string $comment,
		int $confidence
	): bool {
		$outcomeMessage = $this->messageLocalizer->msg( 'requestwikiqueue-' . $outcome )->text();
		$commentText = $this->messageLocalizer->msg( 'requestwiki-ai-decision-dryrun' )
			->params( $outcomeMessage, $comment, $confidence )
			->inContentLanguage()
			->parse();

		$this->wikiRequestManager->addComment(
			comment: $commentText,
			user: User::newSystemUser( 'CreateWiki AI' ),
			log: false,
			type: 'comment',
			notifyUsers: []
		);

		$dryRunMessages = [
			'approve' => 'Wiki request {id} was approved by AI but not automatically created.',
			'moredetails' => 'Wiki request {id} needs revision but was not automatically marked.',
			'decline' => 'Wiki request {id} was declined by AI but not automatically marked.',
			'onhold' => 'Wiki request {id} requires manual review.',
		];

		$this->logger->debug(
			'DRY RUN: ' . ( $dryRunMessages[$outcome] ?? 'Unknown outcome for request {id}! Outcome was {outcome}.' ),
			[
				'id' => $this->id,
				'outcome' => $outcome,
				'reasoning' => $comment,
			]
		);

		return true;
	}

	private function handleLiveRun(
		string $outcome,
		string $comment,
		int $confidence
	): bool {
		$systemUser = User::newSystemUser( 'CreateWiki AI' );
		$unknownCommentText = $this->messageLocalizer->msg( 'requestwiki-ai-error' )
			->inContentLanguage()
			->parse();

		switch ( $outcome ) {
			case 'approve':
				$this->wikiRequestManager->startQueryBuilder();
				$this->wikiRequestManager->approve(
					user: $systemUser,
					comment: $comment
				);
				$this->wikiRequestManager->tryExecuteQueryBuilder();
				$this->logger->debug(
					'Wiki request {id} was automatically approved by AI decision ' .
					'(with {confidence}% confidence) with reason: {comment}',
					[
						'comment' => $comment,
						'confidence' => $confidence,
						'id' => $this->id,
					]
				);
				break;

			case 'moredetails':
				$this->wikiRequestManager->startQueryBuilder();
				$this->wikiRequestManager->moredetails(
					user: $systemUser,
					comment: $comment
				);
				$this->wikiRequestManager->tryExecuteQueryBuilder();
				$this->logger->debug(
					'Wiki request {id} requires more details. Rationale given: {comment}',
					[
						'comment' => $comment,
						'id' => $this->id,
					]
				);
				break;

			case 'decline':
				$this->wikiRequestManager->startQueryBuilder();
				$this->wikiRequestManager->decline(
					user: $systemUser,
					comment: $comment
				);
				$this->wikiRequestManager->tryExecuteQueryBuilder();
				$this->logger->debug(
					'Wiki request {id} was automatically declined by AI decision with reason: {comment}',
					[
						'comment' => $comment,
						'id' => $this->id,
					]
				);
				break;

			case 'onhold':
				$this->wikiRequestManager->addComment(
					comment: $comment,
					user: $systemUser,
					log: false,
					type: 'comment',
					notifyUsers: []
				);
				$this->logger->debug(
					'Wiki request {id} requires manual review and has been placed on hold with reason: {comment}',
					[
						'comment' => $comment,
						'id' => $this->id,
					]
				);
				break;

			default:
				$this->wikiRequestManager->addComment(
					comment: $unknownCommentText,
					user: $systemUser,
					log: false,
					type: 'comment',
					notifyUsers: []
				);
				$this->logger->debug(
					'Wiki request {id} recieved an unknown outcome with comment: {comment}',
					[
						'comment' => $comment,
						'id' => $this->id,
					]
				);
		}

		return true;
	}

	private function queryOpenAI(
		bool $bio,
		string $category,
		array $extraData,
		string $language,
		bool $private,
		string $reason,
		string $sitename,
		string $subdomain,
		string $username,
		int $userRequestsNum
	): ?array {
		try {
			$isBio = $bio ? 'Yes' : 'No';
			$isFork = !empty( $extraData['source'] ) ? 'Yes' : 'No';
			$isNsfw = !empty( $extraData['nsfw'] ) ? 'Yes' : 'No';
			$isPrivate = $private ? 'Yes' : 'No';
			$forkText = !empty( $extraData['sourceurl'] )
				? 'This wiki is forking from this URL: "' .
				htmlspecialchars( $extraData['sourceurl'], ENT_QUOTES ) . '". '
				: '';
			$nsfwReasonText = !empty( $extraData['nsfwtext'] )
				? 'What type of NSFW content will it feature? "' .
				htmlspecialchars( $extraData['nsfwtext'], ENT_QUOTES ) . '". '
				: '';

			$sanitizedReason = sprintf(
				'Wiki name: "%s". Subdomain: "%s". Requester: "%s". ' .
				'Number of previous requests: "%d". Language: "%s". ' .
				'Focuses on real people/groups? "%s". Private wiki? "%s". Category: "%s". ' .
				'Contains content that is not safe for work? "%s". %s%s' .
				'Wiki request description: "%s"',
				htmlspecialchars( $sitename, ENT_QUOTES ),
				htmlspecialchars( $subdomain, ENT_QUOTES ),
				htmlspecialchars( $username, ENT_QUOTES ),
				$userRequestsNum,
				htmlspecialchars( $language, ENT_QUOTES ),
				$isBio,
				$isPrivate,
				htmlspecialchars( $category, ENT_QUOTES ),
				$isNsfw,
				$nsfwReasonText,
				$forkText,
				htmlspecialchars( trim( str_replace( [ "\r\n", "\r" ], "\n", $reason ) ), ENT_QUOTES )
			);

			// Step 1: Create a new thread
			$threadData = $this->createRequest( '/threads', 'POST', [
				'messages' => [ [
					'role' => 'user',
					'content' => $sanitizedReason,
				] ]
			] );

			$threadId = $threadData['id'] ?? null;

			$this->logger->debug( 'Stage 1 for AI decision: Created thread.' );

			$this->logger->debug(
				'OpenAI returned for stage 1 of {id}: {threadData}',
				[
					'id' => $this->id,
					'comment' => json_encode( $threadData ),
				]
			);

			if ( !$threadId ) {
				$this->logger->error( 'OpenAI did not return a threadId!' );
				$this->setLastError( 'Run ' . $this->id . ' failed. No threadId returned.' );
				return $threadData;
			}

			// Step 2: Run the message
			$runData = $this->createRequest( '/threads/' . $threadId . '/runs', 'POST', [
				'assistant_id' => $this->config->get( ConfigNames::OpenAIConfig )['assistantid'] ?? '',
			] );

			$runId = $runData['id'] ?? null;

			$this->logger->debug(
				'Stage 2 for AI decision of {id}: Message ran.',
				[
					'id' => $this->id,
				]
			);

			$this->logger->debug(
				'OpenAI returned the following data for stage 2 of {id}: {runData}',
				[
					'id' => $this->id,
					'runData' => json_encode( $runData ),
				]
			);

			if ( !$runId ) {
				$this->logger->error( 'OpenAI did not return a runId!' );
				$this->setLastError( 'Run ' . $this->id . ' failed. No runId returned.' );
				return $runData;
			}

			// Step 3: Poll the status of the run
			$status = 'running';
			$this->logger->debug( 'Stage 3 for AI decision: Polling status...' );

			while ( $status === 'running' ) {
				sleep( 5 );

				$this->logger->debug( 'Sleeping for 5 seconds...' );

				$statusData = $this->createRequest( '/threads/' . $threadId . '/runs/' . $runId, 'GET', [] );
				$status = $statusData['status'] ?? 'failed';

				$this->logger->debug(
					'Stage 2 for AI decision of {id}: Retrieved run status for {runId}',
					[
						'id' => $this->id,
						'runId' => $runId,
					]
				);

				$this->logger->debug(
					'OpenAI returned the following data for stage 3 of {id}: {statusData}',
					[
						'id' => $this->id,
						'statusData' => json_encode( $statusData ),
					]
				);

				if ( $status === 'in_progress' ) {
					$status = 'running';
				} elseif ( $status === 'failed' ) {
					$this->logger->error(
						'Run {runId} failed for {id}! OpenAI returned {statusData}',
						[
							'id' => $this->id,
							'runId' => $runId,
							'statusData' => json_encode( $statusData ),
						]
					);

					$this->setLastError( 'Run ' . $runId . ' failed.' );

					return $statusData;
				}
			}

			// Step 4: Query for messages in the thread
			$messagesData = $this->createRequest( '/threads/' . $threadId . '/messages', 'GET', [] );

			$this->logger->debug(
				'Stage 4 for AI decision of {id}: Queried for messages in thread {threadId}.',
				[
					'id' => $this->id,
					'threadId' => $threadId,
				]
			);

			$this->logger->debug(
				'OpenAI returned the following data for stage 4 of {id}: {messagesData}',
				[
					'id' => $this->id,
					'messagesData' => json_encode( $messagesData ),
				]
			);

			$finalResponseContent = $messagesData['data'][0]['content'][0]['text']['value'] ?? '';
			return (array)json_decode( $finalResponseContent, true );
		} catch ( Exception $e ) {
			$this->logger->error( 'HTTP request failed: ' . $e->getMessage() );
			$this->setLastError( 'An exception occured! The following issue was reported: ' . $e->getMessage() );
			return null;
		}
	}

	private function createRequest(
		string $endpoint,
		string $method,
		array $data
	): ?array {
		$url = $this->baseApiUrl . $endpoint;

		$this->logger->debug( 'Creating HTTP request to OpenAI...' );

		// Create a multi-client
		$requestOptions = [
			'url' => $url,
			'method' => $method,
			'headers' => [
				'Authorization'	=> 'Bearer ' . $this->apiKey,
				'Content-Type'	=> 'application/json',
				'OpenAI-Beta'	=> 'assistants=v2',
			],
		];

		if ( $method === 'POST' ) {
			$requestOptions['body'] = json_encode( $data );
			$this->logger->debug( 'POST request detected. Attaching POST data to body...' );
		}

		$request = $this->httpRequestFactory->createMultiClient(
			[ 'proxy' => $this->config->get( MainConfigNames::HTTPProxy ) ]
		)->run( $requestOptions, [ 'reqTimeout' => 15 ] );

		$this->logger->debug(
			'HTTP request for {id} to OpenAI executed. Response was: {request}',
			[
				'id' => $this->id,
				'request' => json_encode( $request ),
			]
		);

		if ( $request['code'] !== 200 ) {
			$this->logger->error(
				'Request to {url} failed with status {code}',
				[
					'code' => $request['code'],
					'url' => $url,
				]
			);

			return null;
		}

		return (array)json_decode( $request['body'], true );
	}

	private function canAutoApprove(): bool {
		$this->wikiRequestManager->loadFromID( $this->id );

		$filter = CreateWikiRegexConstraint::regexFromArray(
			$this->config->get( ConfigNames::AutoApprovalFilter ), '/(', ')+/',
			ConfigNames::AutoApprovalFilter
		);

		$this->logger->debug(
			'Checking wiki request {id} against the auto approval denylist filter...',
			[
				'id' => $this->id,
			]
		);

		if ( preg_match( $filter, strtolower( $this->wikiRequestManager->getReason() ) ) ) {
			$this->logger->debug(
				'Wiki request {id} matched against the auto approval denylist filter! A manual review is required.',
				[
					'id' => $this->id,
				]
			);

			return false;
		}

		$this->logger->debug(
			'Wiki request {id} passed the auto approval filter review!',
			[
				'id' => $this->id,
			]
		);

		return true;
	}
}
