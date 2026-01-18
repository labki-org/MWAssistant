<?php

namespace MWAssistant\Hooks;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;
use MWAssistant\Config;
use MWAssistant\MCP\EmbeddingsClient;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Revision\SlotRecord;

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
            error_log("[MWAssistant] AutoEmbed disabled in Config.");
            return;
        }
        error_log("[MWAssistant] AutoEmbed Triggered for: " . $wikiPage->getTitle()->getPrefixedText());

        $title = $wikiPage->getTitle();
        if (!$title) {
            error_log("[MWAssistant] AutoEmbed: No title found.");
            return;
        }

        $pageTitle = $title->getPrefixedText();
        $namespace = $title->getNamespace();
        $timestamp = $revisionRecord->getTimestamp();

        // Skip talk pages & user pages to avoid embedding huge volumes of irrelevant content
        if ($title->isTalkPage() || $title->getNamespace() === NS_USER) {
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

        // Extract text BEFORE processing
        $text = ContentHandler::getContentText($content);
        if (!is_string($text) || trim($text) === '') {
            return;
        }

        // Execute immediately (synchronous) for reliability
        $client = new EmbeddingsClient();
        try {
            $res = $client->updatePage($user, $pageTitle, $text, $namespace, $timestamp);
            if (isset($res['error'])) {
                LoggerFactory::getInstance('MWAssistant')
                    ->error('AutoEmbed update failed for {page}: {error}', [
                        'page' => $pageTitle,
                        'error' => $res['message'] ?? 'Unknown error'
                    ]);
            } else {
                LoggerFactory::getInstance('MWAssistant')
                    ->debug('AutoEmbed success for {page}', ['page' => $pageTitle]);
            }
        } catch (\Throwable $e) {
            LoggerFactory::getInstance('MWAssistant')
                ->error('AutoEmbed update exception: ' . $e->getMessage());
        }
    }

    /**
     * Fired after a page is deleted.
     *
     * @param \WikiPage $wikiPage
     * @param UserIdentity $user
     * @param string $reason
     * @param int $id
     * @param mixed $content
     * @param mixed $logEntry
     * @param bool $archived
     */
    public static function onPageDeleteComplete(
        $wikiPage,
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

        $title = $wikiPage->getTitle();
        if (!$title) {
            return;
        }

        if ($title->isTalkPage() || $title->getNamespace() === NS_USER) {
            return;
        }

        $pageTitle = $title->getPrefixedText();

        // Execute immediately (synchronous) for reliability
        $client = new EmbeddingsClient();
        try {
            $res = $client->deletePage($user, $pageTitle);
            if (isset($res['error'])) {
                LoggerFactory::getInstance('MWAssistant')
                    ->error('AutoEmbed delete failed for {page}: {error}', [
                        'page' => $pageTitle,
                        'error' => $res['message'] ?? 'Unknown error'
                    ]);
            } else {
                LoggerFactory::getInstance('MWAssistant')
                    ->debug('AutoEmbed delete success for {page}', ['page' => $pageTitle]);
            }
        } catch (\Throwable $e) {
            LoggerFactory::getInstance('MWAssistant')
                ->error('AutoEmbed delete error: ' . $e->getMessage());
        }
    }
}
