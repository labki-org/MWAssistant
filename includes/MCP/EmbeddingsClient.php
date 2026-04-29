<?php

namespace MWAssistant\MCP;

use MWAssistant\Config;
use MWAssistant\HttpClient;
use MWAssistant\JWT;
use MediaWiki\User\UserIdentity;

/**
 * Client for MCP embeddings endpoints.
 *
 * Endpoints:
 *  - POST /embeddings/page        (create/update page embedding)
 *  - DELETE /embeddings/page      (delete page embedding)
 *  - GET /embeddings/stats        (stats/health for embeddings index)
 */
class EmbeddingsClient
{

    /** @var HttpClient */
    private HttpClient $client;

    public function __construct()
    {
        // Explicitly configure MCP base URL for embeddings operations.
        $this->client = new HttpClient(Config::getMCPBaseUrl());
    }

    /**
     * Create or update embeddings for a given page.
     *
     * $revisionId, when supplied, lets the dashboard compare embedding sync
     * against page_latest exactly — avoiding the false-outdated reports
     * caused by page_touched cache invalidations.
     */
    public function updatePage(
        UserIdentity $user,
        string $title,
        string $content,
        int $namespace = 0,
        ?string $timestamp = null,
        ?int $revisionId = null
    ): array {
        $jwt = $this->createToken($user);

        $payload = [
            'title' => $title,
            'content' => $content,
            'namespace' => $namespace,
            'last_modified' => $timestamp,
        ];
        // Treat 0 as "no rev id known" — page_latest can momentarily be 0 on
        // pages that haven't been indexed by the revision table. The server
        // requires rev_id >= 1, so sending 0 would fail validation.
        if ($revisionId !== null && $revisionId > 0) {
            $payload['rev_id'] = $revisionId;
        }

        $resp = $this->client->postJson('/embeddings/page', $payload, $jwt);
        return $this->client->handleResponse($resp, 'embeddings update');
    }

    /**
     * Delete embeddings for a given page title.
     */
    public function deletePage(UserIdentity $user, string $title): array
    {
        $jwt = $this->createToken($user);

        $payload = ['title' => $title];

        $resp = $this->client->request('DELETE', '/embeddings/page', $payload, $jwt);
        return $this->client->handleResponse($resp, 'embeddings delete');
    }

    /**
     * Fetch basic embeddings index statistics.
     */
    public function getStats(UserIdentity $user): array
    {
        $jwt = $this->createToken($user);
        $resp = $this->client->getJson('/embeddings/stats', [], $jwt);
        return $this->client->handleResponse($resp, 'embeddings stats');
    }

    /**
     * Build MW→MCP JWT for embeddings operations.
     *
     * @param UserIdentity $user
     * @return string
     */
    private function createToken(UserIdentity $user): string
    {
        $roles = HttpClient::getUserRoles($user);
        return JWT::createMWToMCPToken($user, $roles, ['embeddings']);
    }
}
