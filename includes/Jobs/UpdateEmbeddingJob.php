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
                return $this->handleClientError(
                    $logger,
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

    /**
     * Decide whether a server error from EmbeddingsClient should be retried.
     *
     * 4xx (other than 429) is the server telling us the request itself is
     * wrong — retrying with the same payload will fail the same way. Drop the
     * job loudly and let humans investigate. 5xx, 429, and transport errors
     * are transient by nature: backoff and retry.
     *
     * @param array{status?:int|null,message?:string} $res
     */
    private function handleClientError(LoggerInterface $logger, string $title, array $res): bool
    {
        $status = (int) ($res['status'] ?? 0);
        $msg = $res['message'] ?? 'embedding update failed';

        $isPermanent = $status >= 400 && $status < 500 && $status !== 429;

        if ($isPermanent) {
            $logger->error(
                'UpdateEmbeddingJob {title} -> permanent failure ({status}); dropping job: {err}',
                ['title' => $title, 'status' => $status, 'err' => $msg]
            );
            // Returning true marks the job complete so MW removes it. The
            // ERROR log is the dead-letter signal — searchable by the title
            // and the status code.
            return true;
        }

        $logger->warning(
            'UpdateEmbeddingJob {title} -> transient failure ({status}); will retry: {err}',
            ['title' => $title, 'status' => $status, 'err' => $msg]
        );
        $this->setLastError($msg);
        return false;
    }

    private function logger(): LoggerInterface
    {
        return LoggerFactory::getInstance(Config::LOGGER_CHANNEL);
    }
}
