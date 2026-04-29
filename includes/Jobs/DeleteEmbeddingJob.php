<?php

namespace MWAssistant\Jobs;

use MediaWiki\Title\Title;
use MWAssistant\MCP\EmbeddingsClient;
use Throwable;

/**
 * Background job: tell the MCP server to drop all embeddings for a page.
 *
 * The original prefixed title is carried via params['prefixed_title'] because
 * by the time the job runs the local Title may already be gone (delete) or
 * point to a different page (move). We always send the title that was current
 * at the moment of enqueue.
 */
class DeleteEmbeddingJob extends EmbeddingJobBase
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
            $res = $client->deletePage($this->resolveSystemUser(), $prefixedTitle);

            if (isset($res['error'])) {
                return $this->classifyClientError(
                    $logger,
                    'DeleteEmbeddingJob',
                    $prefixedTitle,
                    $res
                );
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
}
