<?php

namespace MWAssistant\MCP;

use MWAssistant\HttpClient;
use MWAssistant\JWT;
use MediaWiki\User\UserIdentity;

/**
 * Client for MCP chat completion endpoint.
 *
 * Responsible for:
 *  - Constructing and sending chat payloads to the MCP server.
 *  - Attaching a MW→MCP JWT with appropriate scopes and roles.
 *  - Normalizing responses into a consistent array shape.
 */
class ChatClient
{

    /** @var HttpClient */
    private HttpClient $client;

    public function __construct()
    {
        // Uses default MCP base URL configured in HttpClient / Config.
        $this->client = new HttpClient();
    }

    /**
     * Send a chat request to the MCP server and return its response.
     *
     * @param UserIdentity $user
     * @param array $messages List of message objects (role/content, etc.)
     * @param string|null $sessionId Optional session identifier for stateful chats
     * @param string $context Chat context ('chat' or 'editor')
     *
     * @return array Normalized response body (or error descriptor)
     */
    public function chat(UserIdentity $user, array $messages, ?string $sessionId = null, string $context = 'chat'): array
    {
        $roles = HttpClient::getUserRoles($user);
        $jwt = JWT::createMWToMCPToken($user, $roles, ['chat_completion']);

        $payload = [
            'messages' => $messages,
            'max_tokens' => 512,
            'context' => $context,
        ];

        if ($sessionId !== null && $sessionId !== '') {
            $payload['session_id'] = $sessionId;
        }

        $resp = $this->client->postJson('/chat/', $payload, $jwt);

        return $this->client->handleResponse($resp, 'chat');
    }

    /**
     * Get list of chat sessions for a user.
     *
     * @param UserIdentity $user
     * @param int $limit Maximum sessions to return
     * @param int $offset Pagination offset
     * @return array List of session summaries or error
     */
    public function getSessions(UserIdentity $user, int $limit = 50, int $offset = 0): array
    {
        $roles = HttpClient::getUserRoles($user);
        $jwt = JWT::createMWToMCPToken($user, $roles, ['chat_completion']);

        $resp = $this->client->getJson('/chat/sessions', [
            'limit' => $limit,
            'offset' => $offset,
        ], $jwt);

        return $this->client->handleResponse($resp, 'list sessions');
    }

    /**
     * Get a specific session with its message history.
     *
     * @param UserIdentity $user
     * @param string $sessionId
     * @return array Session data with messages or error
     */
    public function getSession(UserIdentity $user, string $sessionId): array
    {
        $roles = HttpClient::getUserRoles($user);
        $jwt = JWT::createMWToMCPToken($user, $roles, ['chat_completion']);

        $resp = $this->client->getJson("/chat/sessions/{$sessionId}", [], $jwt);

        return $this->client->handleResponse($resp, 'get session');
    }

    /**
     * Delete a chat session.
     *
     * @param UserIdentity $user
     * @param string $sessionId
     * @return array Deletion result or error
     */
    public function deleteSession(UserIdentity $user, string $sessionId): array
    {
        $roles = HttpClient::getUserRoles($user);
        $jwt = JWT::createMWToMCPToken($user, $roles, ['chat_completion']);

        $resp = $this->client->delete("/chat/sessions/{$sessionId}", $jwt);

        return $this->client->handleResponse($resp, 'delete session');
    }
}
