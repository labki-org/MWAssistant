<?php

namespace MWAssistant\Hooks;

use MediaWiki\Content\ContentHandler;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use MWAssistant\Config;
use MWAssistant\MCP\EmbeddingsClient;
use Psr\Log\LoggerInterface;

/**
 * Automatically updates or deletes embeddings on the MCP server
 * whenever a page is saved or removed.
 */
class AutoEmbeddingHooks
{

    /**
     * Fired after a successful page save (edit, new page, or null edit).
     *
     * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageSaveComplete
     *
     * @param \WikiPage $wikiPage
     * @param UserIdentity $user
     * @param string $summary
     * @param int $flags
     * @param RevisionRecord $revisionRecord
     * @param mixed $editResult
     */
    public static function onPageSaveComplete(
        $wikiPage,
        UserIdentity $user,
        string $summary,
        int $flags,
        RevisionRecord $revisionRecord,
        $editResult
    ): void {
        if (!Config::isAutoEmbedEnabled()) {
            return;
        }

        $title = $wikiPage->getTitle();
        if (!$title || self::shouldSkipTitle($title)) {
            return;
        }

        $content = $revisionRecord->getContent(SlotRecord::MAIN);
        if (!$content) {
            return;
        }

        $text = ContentHandler::getContentText($content);
        if (!is_string($text) || trim($text) === '') {
            return;
        }

        $pageTitle = $title->getPrefixedText();
        self::runEmbeddingOp(
            'update',
            $pageTitle,
            fn (EmbeddingsClient $c) => $c->updatePage(
                $user,
                $pageTitle,
                $text,
                $title->getNamespace(),
                $revisionRecord->getTimestamp()
            )
        );
    }

    /**
     * Fired after a page is deleted.
     *
     * @param \MediaWiki\Page\ProperPageIdentity $page
     * @param UserIdentity $user
     * @param string $reason
     * @param int $id
     * @param mixed $content
     * @param mixed $logEntry
     * @param bool $archived
     */
    public static function onPageDeleteComplete(
        $page,
        UserIdentity $user,
        string $reason,
        int $id,
        $content,
        $logEntry,
        bool $archived
    ): void {
        if (!Config::isAutoEmbedEnabled()) {
            return;
        }

        $title = Title::makeTitle($page->getNamespace(), $page->getDBkey());
        if (self::shouldSkipTitle($title)) {
            return;
        }

        $pageTitle = $title->getPrefixedText();
        self::runEmbeddingOp(
            'delete',
            $pageTitle,
            fn (EmbeddingsClient $c) => $c->deletePage($user, $pageTitle)
        );
    }

    /**
     * Skip talk pages and user pages to avoid embedding huge volumes
     * of irrelevant content.
     */
    private static function shouldSkipTitle(Title $title): bool
    {
        return $title->isTalkPage() || $title->getNamespace() === NS_USER;
    }

    /**
     * Execute an embedding operation and log success / failure consistently.
     *
     * @param string $op Operation label used in log messages ("update" / "delete").
     * @param string $pageTitle Page being operated on (for log context).
     * @param callable $callback Receives an EmbeddingsClient and returns its response array.
     */
    private static function runEmbeddingOp(string $op, string $pageTitle, callable $callback): void
    {
        $logger = self::logger();
        $client = new EmbeddingsClient();

        try {
            $res = $callback($client);
            if (isset($res['error'])) {
                $logger->error('AutoEmbed {op} failed for {page}: {error}', [
                    'op' => $op,
                    'page' => $pageTitle,
                    'error' => $res['message'] ?? 'Unknown error',
                ]);
            } else {
                $logger->debug('AutoEmbed {op} success for {page}', [
                    'op' => $op,
                    'page' => $pageTitle,
                ]);
            }
        } catch (\Throwable $e) {
            $logger->error('AutoEmbed {op} exception for {page}: {error}', [
                'op' => $op,
                'page' => $pageTitle,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function logger(): LoggerInterface
    {
        return LoggerFactory::getInstance(Config::LOGGER_CHANNEL);
    }
}
