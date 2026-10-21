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
 *
 * If a (non-redirect) page exists under that title again when the job runs —
 * undeleted, re-created, imported or moved back — the delete is skipped: the
 * event that brought it back queued its own UpdateEmbeddingJob, which may
 * already have run, and deleting now would wipe fresh vectors. Redirects
 * (e.g. left behind by a move) are still cleared.
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
            $current = Title::newFromText($prefixedTitle);
            if ($current && $current->canExist()) {
                $wikiPage = $this->loadLatestPage($current);
                if ($wikiPage->exists() && !$wikiPage->isRedirect()) {
                    $logger->debug('DeleteEmbeddingJob {title} -> page exists again; skipping', [
                        'title' => $prefixedTitle,
                    ]);
                    return true;
                }
            }

            $systemUser = $this->resolveSystemUser();
            if (!$systemUser) {
                return $this->failMissingSystemUser('DeleteEmbeddingJob', $prefixedTitle);
            }

            $client = new EmbeddingsClient();
            $res = $client->deletePage($systemUser, $prefixedTitle);

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
