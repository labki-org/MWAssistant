<?php

namespace MWAssistant\Jobs;

use Job;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MWAssistant\Config;
use MWAssistant\MCP\EmbeddingsClient;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job: re-embed a page on the MCP server.
 *
 * Replaces the synchronous HTTP call that used to happen inside the page-save
 * hook and the dashboard's batch-update loop. Doing this in a job means:
 *  - page saves no longer block on MCP latency,
 *  - bulk re-embeds aren't bounded by PHP's max_execution_time,
 *  - failures retry automatically with backoff (the JobQueue's normal behavior),
 *  - duplicate enqueues collapse via removeDuplicates.
 */
class UpdateEmbeddingJob extends Job
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
                // enqueue and run); nothing to embed. Drop the job rather
                // than retry — the situation won't fix itself.
                return true;
            }

            $text = ContentHandler::getContentText($content);
            if (!is_string($text) || trim($text) === '') {
                return true;
            }

            $revId = $wikiPage->getLatest() ?: null;
            $timestamp = $wikiPage->getTimestamp();

            $client = new EmbeddingsClient();
            $res = $client->updatePage(
                $this->resolveUser(),
                $title->getPrefixedText(),
                $text,
                $title->getNamespace(),
                $timestamp,
                $revId
            );

            if (isset($res['error'])) {
                $msg = $res['message'] ?? 'embedding update failed';
                $logger->warning('UpdateEmbeddingJob {title} -> server error: {err}', [
                    'title' => $title->getPrefixedText(),
                    'err' => $msg,
                ]);
                $this->setLastError($msg);
                return false;  // triggers retry with backoff
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

    /**
     * Jobs run outside any web request, so there's no live UserIdentity. We use
     * a dedicated system user — its only role is to mint the JWT carrying the
     * 'embeddings' scope; the MCP server doesn't track per-user state for these
     * service-to-service calls.
     */
    private function resolveUser(): User
    {
        return User::newSystemUser('MWAssistant Embedder', ['steal' => true]);
    }

    private function logger(): LoggerInterface
    {
        return LoggerFactory::getInstance(Config::LOGGER_CHANNEL);
    }
}
