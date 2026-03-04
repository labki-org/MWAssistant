<?php

namespace MWAssistant\MCP;

use MWAssistant\HttpClient;
use MWAssistant\JWT;
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
        $roles = HttpClient::getUserRoles($user);
        $jwt = JWT::createMWToMCPToken($user, $roles, ['search']);

        $payload = [
            'query' => $query,
        ];

        $resp = $this->client->postJson('/search/', $payload, $jwt);
        return $this->client->handleResponse($resp, 'search');
    }
}
