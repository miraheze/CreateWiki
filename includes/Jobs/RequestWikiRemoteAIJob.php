<?php

namespace Miraheze\CreateWiki\Jobs;

use Exception;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\JobQueue\Job;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\User\User;
use MessageLocalizer;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Psr\Log\LoggerInterface;
use Wikimedia\Stats\StatsFactory;
use function count;
use function htmlspecialchars;
use function is_array;
use function json_decode;
use function json_encode;
use function preg_match;
use function sprintf;
use function str_replace;
use function strtolower;
use function substr;
use function trim;
use const ENT_QUOTES;

class RequestWikiRemoteAIJob extends Job {

	public const JOB_NAME = 'RequestWikiRemoteAIJob';

	private readonly MessageLocalizer $messageLocalizer;

	private readonly string $baseApiUrl;
	private readonly int $id;

	public function __construct(
		array $params,
		private readonly Config $config,
		private readonly LoggerInterface $logger,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly StatsFactory $statsFactory,
		private readonly WikiRequestManager $wikiRequestManager
	) {
		parent::__construct( self::JOB_NAME, $params );
		$this->messageLocalizer = RequestContext::getMain();

		$this->baseApiUrl = $this->config->get( ConfigNames::AIConfig )['baseurl'] ?? '';
		$this->id = $params['id'];
	}

	/** @inheritDoc */
	public function run(): bool {
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

		// Query Ollama for decision
		$this->logger->debug(
			'Querying Ollama for decision on wiki request {id}...',
			[
				'id' => $this->id,
			]
		);

		$rawResponse = $this->queryOllama(
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

		$apiResponse = json_decode( $rawResponse, true );

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

			$this->statsFactory->getCounter( 'createwiki_ai_error_total' )->increment();
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

			$this->statsFactory->getCounter( 'createwiki_ai_error_total' )->increment();
		}

		// Extract response details with default fallbacks
		$responseData = json_decode( $apiResponse['response'], true );
		$confidence = (int)( $responseData['confidence'] ?? 0 );
		$outcome = $responseData['outcome'] ?? 'unknown';
		$comment = $responseData['public_comment'] ?? 'No comment provided. Please check logs.';

		$this->logger->debug(
			'AI decision for wiki request {id} was {outcome} (with {confidence}% confidence) with reasoning: {comment}',
			[
				'comment' => $comment,
				'confidence' => $confidence,
				'id' => $this->id,
				'outcome' => $outcome,
			]
		);

		if ( $this->config->get( ConfigNames::AIConfig )['dryrun'] ?? false ) {
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
					'Wiki request {id} received an unknown outcome with comment: {comment}',
					[
						'comment' => $comment,
						'id' => $this->id,
					]
				);
		}

		/** @phan-suppress-next-line PhanPossiblyUndeclaredMethod */
		$this->statsFactory->getCounter( 'createwiki_ai_outcome_total' )
			->setLabel( 'outcome', $outcome )
			->increment();

		return true;
	}

	// Query Ollama's /api/generate endpoint with a single prompt.
	private function queryOllama(
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

			// POST to Ollama /api/generate with stream=false so we get a single JSON response
			$payload = [
				'format' => [
					'type' => 'object',
					'properties' => [
						'confidence' => [
							'type' => 'integer',
							'minimum' => 0,
							'maximum' => 100
						],
						'outcome' => [
							'type' => 'string',
							'enum' => [
								'approve',
								'decline',
								'moredetails',
								'onhold'
							],
						],
						'public_comment' => [
							'type' => 'string'
						],
					],
					'required' => [
						'confidence',
						'outcome',
						'public_comment'
					],
				],
				'model' => $this->config->get( ConfigNames::AIConfig )['model'] ?? 'default',
				'prompt' => $sanitizedReason,
				'stream' => false,
			];

			$response = $this->createRequest( '/api/generate', 'POST', $payload );

			$this->logger->debug(
				'Ollama returned for {id}: {response}',
				[
					'id' => $this->id,
					'response' => json_encode( $response ),
				]
			);

			if ( !$response ) {
				$this->setLastError( 'Ollama query failed: empty or non-200 response.' );
				return null;
			}

			// Ollama /api/generate returns: { "response": "<assistant text>", ... }
			$finalText = $response['response'] ?? '';

			// Your model is expected to return a JSON object as text; parse it
			$decoded = json_decode( $finalText, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}

			// If the model did not return valid JSON, surface an error wrapper so your existing handling logs it
			return [
				'error' => 'Model did not return valid JSON',
				'raw' => $finalText,
				'outcome' => 'unknown',
				'public_comment' => 'AI response was not valid JSON. A human review is required.',
				'confidence' => 0,
			];
		} catch ( Exception $e ) {
			$this->logger->error( 'HTTP request failed: ' . $e->getMessage() );
			$this->setLastError( 'An exception occurred! The following issue was reported: ' . $e->getMessage() );
			return null;
		}
	}

	private function createRequest(
		string $endpoint,
		string $method,
		array $data
	): ?array {
		$url = $this->baseApiUrl . $endpoint;

		$this->logger->debug( 'Creating HTTP request to Ollama...' );

		// Build request options (no auth headers needed for Ollama by default)
		$requestOptions = [
			'url' => $url,
			'method' => $method,
			'headers' => [
				'Content-Type' => 'application/json',
			],
		];

		if ( $method === 'POST' ) {
			$requestOptions['body'] = json_encode( $data );
			$this->logger->debug( 'POST request detected. Attaching POST data to body...' );
		}

		$request = $this->httpRequestFactory->createMultiClient(
			[ 'proxy' => $this->config->get( MainConfigNames::HTTPProxy ) ]
		)->run( $requestOptions, [ 'reqTimeout' => 30 ] );

		$this->logger->debug(
			'HTTP request for {id} to Ollama executed. Response was: {request}',
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
