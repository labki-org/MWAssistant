<?php

namespace MWAssistant\MCP;

use MWAssistant\Config;
use MWAssistant\HttpClient;
use MWAssistant\JWT;
use MediaWiki\MediaWikiServices;
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
        return $this->handleResponse($resp);
    }

    /**
     * Delete embeddings for a given page title.
     *
     * Assumes MCP server exposes:
     *   DELETE /embeddings/page    with JSON body { "title": "<title>" }
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

        // Assumes HttpClient::request(method, path, payload, jwt) is supported.
        $resp = $this->client->request('DELETE', '/embeddings/page', $payload, $jwt);
        return $this->handleResponse($resp);
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
        return $this->handleResponse($resp);
    }

    /**
     * Build MW→MCP JWT for embeddings operations.
     *
     * @param UserIdentity $user
     * @return string
     */
    private function createToken(UserIdentity $user): string
    {
        $roles = MediaWikiServices::getInstance()
            ->getUserGroupManager()
            ->getUserGroups($user);

        return JWT::createMWToMCPToken($user, $roles, ['embeddings']);
    }

    /**
     * Normalize MCP HTTP response into a consistent shape.
     *
     * On success:
     *  - returns $resp['body'] (or [] if missing)
     *
     * On error:
     *  - returns ['error' => true, 'status' => int|null, 'message' => string]
     *
     * @param array $resp
     * @return array
     */
    private function handleResponse(array $resp): array
    {
        $ok = $resp['ok'] ?? false;

        if (!$ok) {
            $code = $resp['code'] ?? null;
            $body = $resp['body'] ?? null;
            $bodyStr = is_string($body) ? $body : json_encode($body);

            return [
                'error' => true,
                'status' => $code,
                'message' => 'MCP embeddings error: ' . ($bodyStr ?? 'Unknown error'),
            ];
        }

        return $resp['body'] ?? [];
    }
}
