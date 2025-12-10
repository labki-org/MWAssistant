<?php

namespace MWAssistant\Hooks;

use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Deferred\DeferredUpdates;
use MWAssistant\Config;
use MWAssistant\MCP\EmbeddingsClient;

class AutoEmbeddingHooks
{

    public static function onPageSaveComplete(
        $wikiPage,
        UserIdentity $user,
        string $summary,
        int $flags,
        RevisionRecord $revisionRecord,
        $editResult
    ) {
        if (!Config::isAutoEmbedEnabled()) {
            wfDebugLog('mwassistant', 'AutoEmbed disabled, skipping page save.');
            return;
        }
        wfDebugLog('mwassistant', 'AutoEmbed enabled, proceeding to queue update.');

        $title = $wikiPage->getTitle();
        if ($title->isTalkPage() || $title->getNamespace() === NS_USER) {
            return;
        }

        $content = $revisionRecord->getContent(\MediaWiki\Revision\SlotRecord::MAIN);
        if (!$content) {
            return;
        }

        // Use ContentHandler to get text
        $text = \ContentHandler::getContentText($content);

        if (!$text) {
            return;
        }

        $pageTitle = $title->getPrefixedText();

        $timestamp = $revisionRecord->getTimestamp();

        DeferredUpdates::addCallable(function () use ($user, $pageTitle, $text, $timestamp) {
            $client = new EmbeddingsClient();
            try {
                $client->updatePage($user, $pageTitle, $text, $timestamp);
            } catch (\Exception $e) {
                MediaWikiServices::getInstance()->getLogger('MWAssistant')->error('AutoEmbed error: ' . $e->getMessage());
            }
        });
    }

    public static function onPageDeleteComplete(
        $wikiPage,
        UserIdentity $user,
        string $reason,
        int $id,
        $content,
        $logEntry,
        $archived
    ) {
        if (!Config::isAutoEmbedEnabled()) {
            return;
        }

        $title = $wikiPage->getTitle();
        if ($title->isTalkPage() || $title->getNamespace() === NS_USER) {
            return;
        }

        $pageTitle = $title->getPrefixedText();

        DeferredUpdates::addCallable(function () use ($user, $pageTitle) {
            $client = new EmbeddingsClient();
            try {
                $client->deletePage($user, $pageTitle);
            } catch (\Exception $e) {
                MediaWikiServices::getInstance()->getLogger('MWAssistant')->error('AutoEmbed delete error: ' . $e->getMessage());
            }
        });
    }
}
