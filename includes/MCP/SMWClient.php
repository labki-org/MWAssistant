<?php

namespace MWAssistant\MCP;

use MWAssistant\HttpClient;
use MWAssistant\JWT;
use MediaWiki\User\UserIdentity;

/**
 * Client for MCP Semantic MediaWiki (SMW) query endpoint.
 *
 * Endpoint:
 *  - POST /smw-query/   with JSON { "query": "<description or query>" }
 *
 * The MCP server is expected to:
 *  - Interpret "query" either as a natural language description or SMW query text.
 *  - Enforce any necessary permission checks server-side.
 */
class SMWClient
{

    /** @var HttpClient */
    private HttpClient $client;

    public function __construct()
    {
        // Uses default MCP base URL configured in HttpClient / Config.
        $this->client = new HttpClient();
    }

    /**
     * Execute an SMW-related query via MPC backend.
     *
     * @param UserIdentity $user
     * @param string $description User prompt or SMW query description
     *
     * @return array
     */
    public function query(UserIdentity $user, string $description): array
    {
        $roles = HttpClient::getUserRoles($user);
        $jwt = JWT::createMWToMCPToken($user, $roles, ['smw_query']);

        $payload = [
            // The user prompt or query description
            'query' => $description,
        ];

        $resp = $this->client->postJson('/smw-query/', $payload, $jwt);
        return $this->client->handleResponse($resp, 'SMW query');
    }
}
