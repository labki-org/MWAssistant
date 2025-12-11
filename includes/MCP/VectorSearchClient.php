<?php

namespace MWAssistant\MCP;

use MWAssistant\HttpClient;
use MWAssistant\JWT;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;

/**
 * Client for MCP search endpoint.
 *
 * Endpoint:
 *  - POST /search/    with JSON { "query": "<string>" }
 */
class VectorSearchClient
{

    /** @var HttpClient */
    private HttpClient $client;

    public function __construct()
    {
        // Uses default MCP base URL configured in HttpClient / Config.
        $this->client = new HttpClient();
    }

    /**
     * Execute a search query against the MCP backend.
     *
     * @param UserIdentity $user
     * @param string $query
     *
     * @return array
     */
    public function search(UserIdentity $user, string $query): array
    {
        $roles = $this->getUserRoles($user);
        $jwt = JWT::createMWToMCPToken($user, $roles, ['search']);

        $payload = [
            'query' => $query,
        ];

        $resp = $this->client->postJson('/search/', $payload, $jwt);
        return $this->handleResponse($resp, 'search');
    }

    /**
     * Fetch user groups/roles for JWT construction.
     *
     * @param UserIdentity $user
     * @return string[]
     */
    private function getUserRoles(UserIdentity $user): array
    {
        return MediaWikiServices::getInstance()
            ->getUserGroupManager()
            ->getUserGroups($user);
    }

    /**
     * Normalize MCP HTTP response into a stable array format.
     *
     * @param array $resp
     * @param string $context
     * @return array
     */
    private function handleResponse(array $resp, string $context): array
    {
        $ok = $resp['ok'] ?? false;

        if (!$ok) {
            $code = $resp['code'] ?? null;
            $body = $resp['body'] ?? null;
            $bodyStr = is_string($body) ? $body : json_encode($body);

            return [
                'error' => true,
                'status' => $code,
                'message' => "MCP {$context} error: " . ($bodyStr ?? 'Unknown error'),
            ];
        }

        return $resp['body'] ?? [];
    }
}
