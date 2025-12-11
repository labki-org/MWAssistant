<?php

namespace MWAssistant\MCP;

use MWAssistant\HttpClient;
use MWAssistant\JWT;
use MediaWiki\MediaWikiServices;
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
        $roles = $this->getUserRoles($user);
        $jwt = JWT::createMWToMCPToken($user, $roles, ['chat_completion']);

        $payload = [
            'messages' => $messages,
            'max_tokens' => 512,
            'context' => $context,
        ];

        if ($sessionId !== null && $sessionId !== '') {
            $payload['session_id'] = $sessionId;
        }

        \wfDebugLog('mwassistant', 'ChatClient payload: ' . json_encode($payload));
        $resp = $this->client->postJson('/chat/', $payload, $jwt);
        \wfDebugLog('mwassistant', 'ChatClient raw response: ' . print_r($resp, true));

        return $this->handleResponse($resp, 'chat');
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
     * On success:
     *   returns $resp['body']
     *
     * On error:
     *   returns ['error' => true, 'status' => int|null, 'message' => string]
     *
     * @param array $resp
     * @param string $context Context label for error messages
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
