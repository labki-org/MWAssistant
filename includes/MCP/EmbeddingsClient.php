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
     * @param UserIdentity $user
     * @param string $title
     * @param string $content
     * @param int $namespace
     * @param string|null $timestamp Last-modified timestamp (optional)
     *
     * @return array
     */
    public function updatePage(
        UserIdentity $user,
        string $title,
        string $content,
        int $namespace = 0,
        ?string $timestamp = null
    ): array {
        $jwt = $this->createToken($user);

        $payload = [
            'title' => $title,
            'content' => $content,
            'namespace' => $namespace,
            'last_modified' => $timestamp,
        ];

        $resp = $this->client->postJson('/embeddings/page', $payload, $jwt);
        return $this->client->handleResponse($resp, 'embeddings update');
    }

    /**
     * Delete embeddings for a given page title.
     *
     * @param UserIdentity $user
     * @param string $title
     *
     * @return array
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
     *
     * @param UserIdentity $user
     * @return array
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
