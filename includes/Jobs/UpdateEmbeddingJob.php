<?php

namespace MWAssistant\Jobs;

use MediaWiki\Content\TextContent;
use MediaWiki\Title\Title;
use MWAssistant\MCP\EmbeddingsClient;
use Throwable;

/**
 * Background job: re-embed a page on the MCP server.
 *
 * Replaces the synchronous HTTP call that used to happen inside the page-save
 * hook and the dashboard's batch-update loop. Doing this in a job means:
 *  - page saves no longer block on MCP latency,
 *  - bulk re-embeds aren't bounded by PHP's max_execution_time,
 *  - failures retry automatically with backoff,
 *  - duplicate enqueues collapse via removeDuplicates.
 *
 * If the page no longer exists when the job runs, its embeddings are
 * deleted instead, so a stale update can never resurrect a deleted page.
 */
class UpdateEmbeddingJob extends EmbeddingJobBase
{
    public const TYPE = 'mwassistantUpdateEmbedding';

    public function __construct(Title $title, array $params = [])
    {
        parent::__construct(self::TYPE, $title, $params);
        // Two saves landing back-to-back should produce one re-embed, not two.
        $this->removeDuplicates = true;
    }

    public function run(): bool
    {
        $logger = $this->logger();
        $title = $this->getTitle();
        // Job falls back to a Special: placeholder when params lack a title.
        if (!$title || !$title->canExist()) {
            $logger->warning('UpdateEmbeddingJob: missing title, dropping');
            return true;
        }

        $prefixedTitle = $title->getPrefixedText();

        try {
            $systemUser = $this->resolveSystemUser();
            if (!$systemUser) {
                return $this->failMissingSystemUser('UpdateEmbeddingJob', $prefixedTitle);
            }

            $wikiPage = $this->loadLatestPage($title);
            $client = new EmbeddingsClient();

            if (!$wikiPage->exists()) {
                // Deleted (or moved away) after this job was queued. The
                // delete job runs in a separate queue and may already have
                // finished, so converge on the current state ourselves rather
                // than leave the page's old vectors behind.
                $res = $client->deletePage($systemUser, $prefixedTitle);
                if (isset($res['error'])) {
                    return $this->classifyClientError(
                        $logger,
                        'UpdateEmbeddingJob',
                        $prefixedTitle,
                        $res
                    );
                }
                $logger->debug('UpdateEmbeddingJob {title} -> page gone, embeddings deleted', [
                    'title' => $prefixedTitle,
                ]);
                return true;
            }

            $content = $wikiPage->getContent();
            if (!$content) {
                // Page row exists but has no readable current revision;
                // nothing to embed. Drop rather than retry — the situation
                // won't fix itself.
                return true;
            }

            // ContentHandler::getContentText() was removed in MW 1.46.
            $text = $content instanceof TextContent ? $content->getText() : null;
            if (!is_string($text) || trim($text) === '') {
                return true;
            }

            $res = $client->updatePage(
                $systemUser,
                $prefixedTitle,
                $text,
                $title->getNamespace(),
                $wikiPage->getTimestamp(),
                $wikiPage->getLatest() ?: null
            );

            if (isset($res['error'])) {
                return $this->classifyClientError(
                    $logger,
                    'UpdateEmbeddingJob',
                    $prefixedTitle,
                    $res
                );
            }

            $logger->debug('UpdateEmbeddingJob {title} -> queued on MCP', [
                'title' => $title->getPrefixedText(),
            ]);
            return true;
        } catch (Throwable $e) {
            $logger->error('UpdateEmbeddingJob exception for {title}: {err}', [
                'title' => $title->getPrefixedText(),
                'err' => $e->getMessage(),
            ]);
            $this->setLastError($e->getMessage());
            return false;
        }
    }
}
