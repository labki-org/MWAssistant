<?php

namespace MWAssistant\MCP;

use MWAssistant\HttpClient;
use MWAssistant\JWT;
use MediaWiki\MediaWikiServices;
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
        $roles = $this->getUserRoles($user);
        $jwt = JWT::createMWToMCPToken($user, $roles, ['smw_query']);

        $payload = [
            // The user prompt or query description
            'query' => $description,
        ];

        $resp = $this->client->postJson('/smw-query/', $payload, $jwt);
        return $this->handleResponse($resp, 'SMW query');
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
