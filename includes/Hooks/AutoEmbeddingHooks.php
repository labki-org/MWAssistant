<?php

namespace MWAssistant\Hooks;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use MWAssistant\Config;
use MWAssistant\Jobs\DeleteEmbeddingJob;
use MWAssistant\Jobs\UpdateEmbeddingJob;
use Psr\Log\LoggerInterface;

/**
 * Hooks that translate page lifecycle events into embedding-update/delete jobs.
 *
 * The hooks themselves are deliberately tiny: they only enqueue. All MCP I/O
 * happens in the job runner so page saves don't block on network latency and
 * MCP outages don't surface as save errors.
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

        self::enqueueUpdate($title);
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

        self::enqueueDelete($title);
    }

    /**
     * Fired after a page move. The old title's embeddings need to be dropped
     * (they're keyed on title, so they'd otherwise become orphans referring
     * to a non-existent page) and the new title needs to be re-embedded.
     *
     * @param PageIdentity $old
     * @param PageIdentity $new
     * @param UserIdentity $user
     * @param int $pageid
     * @param int $redirid
     * @param string $reason
     * @param \MediaWiki\Revision\RevisionRecord $revision
     */
    public static function onPageMoveComplete(
        PageIdentity $old,
        PageIdentity $new,
        UserIdentity $user,
        int $pageid,
        int $redirid,
        string $reason,
        $revision
    ): void {
        if (!Config::isAutoEmbedEnabled()) {
            return;
        }

        $oldTitle = Title::newFromPageIdentity($old);
        $newTitle = Title::newFromPageIdentity($new);

        if ($oldTitle && !self::shouldSkipTitle($oldTitle)) {
            self::enqueueDelete($oldTitle);
        }
        if ($newTitle && !self::shouldSkipTitle($newTitle)) {
            self::enqueueUpdate($newTitle);
        }
    }

    /**
     * Decide whether a title is excluded from embedding by config.
     *
     * Defaults match the historical behavior (skip talk pages and NS_USER) but
     * are overridable via $wgMWAssistantEmbedSkipNamespaces and
     * $wgMWAssistantEmbedTalkPages, e.g. for research wikis where user pages
     * carry valuable content.
     */
    private static function shouldSkipTitle(Title $title): bool
    {
        if ($title->isTalkPage() && !Config::shouldEmbedTalkPages()) {
            return true;
        }
        return in_array($title->getNamespace(), Config::getEmbedSkipNamespaces(), true);
    }

    private static function enqueueUpdate(Title $title): void
    {
        try {
            self::jobQueue()->push(new UpdateEmbeddingJob($title));
        } catch (\Throwable $e) {
            self::logger()->error('Failed to enqueue UpdateEmbeddingJob for {title}: {err}', [
                'title' => $title->getPrefixedText(),
                'err' => $e->getMessage(),
            ]);
        }
    }

    private static function enqueueDelete(Title $title): void
    {
        try {
            // Capture the prefixed title in params: by the time the job runs,
            // the on-wiki Title may resolve differently (e.g. after a move).
            self::jobQueue()->push(new DeleteEmbeddingJob($title, [
                'prefixed_title' => $title->getPrefixedText(),
            ]));
        } catch (\Throwable $e) {
            self::logger()->error('Failed to enqueue DeleteEmbeddingJob for {title}: {err}', [
                'title' => $title->getPrefixedText(),
                'err' => $e->getMessage(),
            ]);
        }
    }

    private static function jobQueue()
    {
        return MediaWikiServices::getInstance()->getJobQueueGroup();
    }

    private static function logger(): LoggerInterface
    {
        return LoggerFactory::getInstance(Config::LOGGER_CHANNEL);
    }
}
