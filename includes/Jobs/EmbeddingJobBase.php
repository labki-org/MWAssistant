<?php

namespace MWAssistant\Jobs;

use Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\User\User;
use MWAssistant\Config;
use Psr\Log\LoggerInterface;

/**
 * Shared scaffolding for jobs that talk to the MCP embeddings API.
 *
 * Centralizes the system-user lookup (jobs run outside any web request, so
 * there's no live UserIdentity) and the 4xx-vs-5xx error classification so
 * UpdateEmbeddingJob and DeleteEmbeddingJob aren't drifting copies of each
 * other.
 */
abstract class EmbeddingJobBase extends Job
{
    /**
     * Resolve the system user used to mint MCP JWTs for service-to-service
     * embedding operations. The MCP server doesn't track per-user state for
     * these calls — the user identity exists only to satisfy the JWT scope.
     */
    protected function resolveSystemUser(): User
    {
        return User::newSystemUser(Config::SYSTEM_USER_NAME, ['steal' => true]);
    }

    /**
     * Decide whether an EmbeddingsClient error response should retry.
     *
     * 4xx (other than 429) means the request itself is malformed — retrying
     * with the same payload will keep failing, so we drop the job loudly and
     * leave humans to investigate via the ERROR log line. 5xx, 429, and
     * transport-level failures are transient: the JobQueue will retry.
     *
     * @param array{status?:int|null,message?:string} $res
     * @return bool true to drop the job (job-complete), false to retry.
     */
    protected function classifyClientError(
        LoggerInterface $logger,
        string $jobName,
        string $title,
        array $res
    ): bool {
        $status = (int) ($res['status'] ?? 0);
        $msg = $res['message'] ?? 'embedding operation failed';

        $isPermanent = $status >= 400 && $status < 500 && $status !== 429;

        if ($isPermanent) {
            $logger->error(
                '{job} {title} -> permanent failure ({status}); dropping job: {err}',
                ['job' => $jobName, 'title' => $title, 'status' => $status, 'err' => $msg]
            );
            return true;
        }

        $logger->warning(
            '{job} {title} -> transient failure ({status}); will retry: {err}',
            ['job' => $jobName, 'title' => $title, 'status' => $status, 'err' => $msg]
        );
        $this->setLastError($msg);
        return false;
    }

    protected function logger(): LoggerInterface
    {
        return LoggerFactory::getInstance(Config::LOGGER_CHANNEL);
    }
}
