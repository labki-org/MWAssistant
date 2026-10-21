<?php

namespace MWAssistant\Hooks;

use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\Hook\PageUndeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\Title;
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
 *
 * Update and delete jobs live in separate queues and can run in either
 * order, so the jobs reconcile against the page's state at run time rather
 * than trusting the event that queued them (see UpdateEmbeddingJob and
 * DeleteEmbeddingJob).
 *
 * AfterImportPage is handled without implementing its interface: it moved
 * from MediaWiki\Hook to MediaWiki\Import\Hook in MW 1.46.
 */
class AutoEmbeddingHooks implements
    PageSaveCompleteHook,
    PageDeleteCompleteHook,
    PageUndeleteCompleteHook,
    PageMoveCompleteHook
{
    /**
     * @inheritDoc
     */
    public function onPageSaveComplete(
        $wikiPage,
        $user,
        $summary,
        $flags,
        $revisionRecord,
        $editResult
    ): void {
        if (!self::isActive()) {
            return;
        }

        $title = $wikiPage->getTitle();
        if (!$title || self::shouldSkipTitle($title)) {
            return;
        }

        self::enqueueUpdate($title);
    }

    /**
     * @inheritDoc
     */
    public function onPageDeleteComplete(
        ProperPageIdentity $page,
        Authority $deleter,
        string $reason,
        int $pageID,
        RevisionRecord $deletedRev,
        ManualLogEntry $logEntry,
        int $archivedRevisionCount
    ): void {
        if (!self::isActive()) {
            return;
        }

        $title = Title::newFromPageIdentity($page);
        if (self::shouldSkipTitle($title)) {
            return;
        }

        self::enqueueDelete($title);
    }

    /**
     * Restored pages would otherwise stay missing from the index until
     * their next edit.
     *
     * @inheritDoc
     */
    public function onPageUndeleteComplete(
        ProperPageIdentity $page,
        Authority $restorer,
        string $reason,
        RevisionRecord $restoredRev,
        ManualLogEntry $logEntry,
        int $restoredRevisionCount,
        bool $created,
        array $restoredPageIds
    ): void {
        if (!self::isActive()) {
            return;
        }

        $title = Title::newFromPageIdentity($page);
        if (self::shouldSkipTitle($title)) {
            return;
        }

        self::enqueueUpdate($title);
    }

    /**
     * Embeddings are keyed on title, so a move would otherwise leave the old
     * title's vectors orphaned — drop them and re-embed under the new title.
     *
     * @inheritDoc
     */
    public function onPageMoveComplete(
        $old,
        $new,
        $user,
        $pageid,
        $redirid,
        $reason,
        $revision
    ): void {
        if (!self::isActive()) {
            return;
        }

        $oldTitle = Title::castFromLinkTarget($old);
        $newTitle = Title::castFromLinkTarget($new);

        if ($oldTitle && !self::shouldSkipTitle($oldTitle)) {
            self::enqueueDelete($oldTitle);
        }
        if ($newTitle && !self::shouldSkipTitle($newTitle)) {
            self::enqueueUpdate($newTitle);
        }
    }

    /**
     * Imports create revisions without going through PageSaveComplete.
     *
     * @see \MediaWiki\Import\Hook\AfterImportPageHook
     * @param Title $title
     * @param mixed $foreignTitle
     * @param int $revCount
     * @param int $sRevCount Number of successfully imported revisions
     * @param array $pageInfo
     * @return void
     */
    public function onAfterImportPage($title, $foreignTitle, $revCount, $sRevCount, $pageInfo): void
    {
        if (!self::isActive() || (int)$sRevCount <= 0) {
            return;
        }

        $title = Title::castFromPageReference($title);
        if (!$title || self::shouldSkipTitle($title)) {
            return;
        }

        self::enqueueUpdate($title);
    }

    private static function isActive(): bool
    {
        return Config::isEnabled() && Config::isAutoEmbedEnabled();
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
