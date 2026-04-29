<?php

namespace MWAssistant\Jobs;

use Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MWAssistant\Config;
use MWAssistant\MCP\EmbeddingsClient;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job: tell the MCP server to drop all embeddings for a page.
 *
 * The original prefixed title is carried via params['prefixed_title'] because
 * by the time the job runs the local Title may already be gone (delete) or
 * point to a different page (move). We always send the title that was current
 * at the moment of enqueue.
 */
class DeleteEmbeddingJob extends Job
{
    public const TYPE = 'mwassistantDeleteEmbedding';

    public function __construct(Title $title, array $params = [])
    {
        parent::__construct(self::TYPE, $title, $params);
        $this->removeDuplicates = true;
    }

    public function run(): bool
    {
        $logger = $this->logger();
        $prefixedTitle = $this->params['prefixed_title']
            ?? ($this->getTitle() ? $this->getTitle()->getPrefixedText() : null);

        if (!$prefixedTitle) {
            $logger->warning('DeleteEmbeddingJob: missing title, dropping');
            return true;
        }

        try {
            $client = new EmbeddingsClient();
            $res = $client->deletePage(
                User::newSystemUser('MWAssistant Embedder', ['steal' => true]),
                $prefixedTitle
            );

            if (isset($res['error'])) {
                $msg = $res['message'] ?? 'embedding delete failed';
                $logger->warning('DeleteEmbeddingJob {title} -> server error: {err}', [
                    'title' => $prefixedTitle,
                    'err' => $msg,
                ]);
                $this->setLastError($msg);
                return false;
            }

            return true;
        } catch (Throwable $e) {
            $logger->error('DeleteEmbeddingJob exception for {title}: {err}', [
                'title' => $prefixedTitle,
                'err' => $e->getMessage(),
            ]);
            $this->setLastError($e->getMessage());
            return false;
        }
    }

    private function logger(): LoggerInterface
    {
        return LoggerFactory::getInstance(Config::LOGGER_CHANNEL);
    }
}
