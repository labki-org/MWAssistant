<?php

namespace MWAssistant\Jobs;

use MediaWiki\Content\ContentHandler;
use MediaWiki\MediaWikiServices;
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
        if (!$title) {
            $logger->warning('UpdateEmbeddingJob: missing title, dropping');
            return true;
        }

        try {
            $services = MediaWikiServices::getInstance();
            $wikiPage = $services->getWikiPageFactory()->newFromTitle($title);
            $content = $wikiPage->getContent();
            if (!$content) {
                // Page exists but has no current revision (deleted between
                // enqueue and run); nothing to embed. Drop rather than retry —
                // the situation won't fix itself.
                return true;
            }

            $text = ContentHandler::getContentText($content);
            if (!is_string($text) || trim($text) === '') {
                return true;
            }

            $client = new EmbeddingsClient();
            $res = $client->updatePage(
                $this->resolveSystemUser(),
                $title->getPrefixedText(),
                $text,
                $title->getNamespace(),
                $wikiPage->getTimestamp(),
                $wikiPage->getLatest() ?: null
            );

            if (isset($res['error'])) {
                return $this->classifyClientError(
                    $logger,
                    'UpdateEmbeddingJob',
                    $title->getPrefixedText(),
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
