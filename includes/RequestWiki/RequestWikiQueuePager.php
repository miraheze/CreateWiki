<?php

namespace Miraheze\CreateWiki\RequestWiki;

use MediaWiki\Context\IContextSource;
use MediaWiki\Languages\LanguageNameUtils;
use MediaWiki\Linker\Linker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Pager\IndexPager;
use MediaWiki\Pager\TablePager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use function htmlspecialchars;
use const ENT_QUOTES;

class RequestWikiQueuePager extends TablePager {

	/** @inheritDoc */
	public $mDefaultDirection = IndexPager::DIR_ASCENDING;

	public function __construct(
		IContextSource $context,
		CreateWikiDatabaseUtils $databaseUtils,
		private readonly LanguageNameUtils $languageNameUtils,
		private readonly LinkRenderer $linkRenderer,
		private readonly UserFactory $userFactory,
		private readonly WikiRequestManager $wikiRequestManager,
		private readonly string $dbname,
		private readonly string $language,
		private readonly string $requester,
		private readonly string $status
	) {
		$this->mDb = $databaseUtils->getCentralWikiReplicaDB();
		parent::__construct( $context, $linkRenderer );
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
		$row = $this->getCurrentRow();

		switch ( $name ) {
			case 'cw_timestamp':
				$formatted = $this->escape( $this->getLanguage()->userTimeAndDate(
					$row->cw_timestamp, $this->getUser()
				) );
				break;
			case 'cw_dbname':
				$formatted = $this->escape( $row->cw_dbname ?? '' );
				break;
			case 'cw_sitename':
				$formatted = $this->escape( $row->cw_sitename ?? '' );
				break;
			case 'cw_user':
				$formatted = Linker::userLink(
					$this->userFactory->newFromId( $row->cw_user )->getId(),
					$this->userFactory->newFromId( $row->cw_user )->getName()
				);
				break;
			case 'cw_url':
				$formatted = $this->escape( $row->cw_url ?? '' );
				break;
			case 'cw_status':
				$formatted = $this->linkRenderer->makeLink(
					SpecialPage::getTitleValueFor( 'RequestWikiQueue', $row->cw_id ),
					$this->msg( 'requestwikiqueue-' . $row->cw_status )->text()
				);
				break;
			case 'cw_language':
				$formatted = $this->languageNameUtils->getLanguageName(
					code: $row->cw_language,
					inLanguage: $this->getLanguage()->getCode()
				);
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
		$dbr = $this->getDatabase();
		$user = $this->getUser();

		$allowedVisibilities = $this->wikiRequestManager->getAllowedVisibilities( $user );

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
				$dbr->expr( 'cw_visibility', '=', $allowedVisibilities ),
			],
			'joins_conds' => [],
		];

		if ( $this->dbname ) {
			$info['conds']['cw_dbname'] = $this->dbname;
		}

		if ( $this->language && $this->language !== '*' ) {
			$info['conds']['cw_language'] = $this->language;
		}

		if ( $this->requester ) {
			$requester = $this->userFactory->newFromName( $this->requester );
			if ( $requester ) {
				$info['conds']['cw_user'] = $requester->getId();
			}
		}

		if ( $this->status && $this->status !== '*' ) {
			$info['conds']['cw_status'] = $this->status;
		} elseif ( !$this->status ) {
			$info['conds']['cw_status'] = 'inreview';
		}

		return $info;
	}

	/** @inheritDoc */
	public function getDefaultSort(): string {
		return 'cw_timestamp';
	}

	/** @inheritDoc */
	public function isFieldSortable( $name ): bool {
		return $name !== 'cw_user';
	}
}
