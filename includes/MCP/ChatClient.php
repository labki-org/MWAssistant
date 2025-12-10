<?php

namespace MWAssistant\MCP;

use MWAssistant\HttpClient;
use MWAssistant\JWT;
use MediaWiki\User\UserIdentity;

class ChatClient
{

    private HttpClient $client;

    public function __construct()
    {
        $this->client = new HttpClient();
    }

    public function chat(UserIdentity $user, array $messages, ?string $sessionId = null): array
    {
        $roles = $this->getUserRoles($user);
        $jwt = JWT::createMWToMCPToken($user, $roles, ['chat_completion']);


        $payload = [
            'messages' => $messages,
            'max_tokens' => 512,
        ];

        if ($sessionId) {
            $payload['session_id'] = $sessionId;
        }


        \wfDebugLog('mwassistant', 'ChatClient payload: ' . json_encode($payload));
        $resp = $this->client->postJson('/chat/', $payload, $jwt);
        \wfDebugLog('mwassistant', 'ChatClient raw response: ' . print_r($resp, true));


        if (!$resp['ok']) {
            return [
                'error' => true,
                'status' => $resp['code'],
                'message' => 'MCP chat error: ' . (is_string($resp['body']) ? $resp['body'] : json_encode($resp['body']))
            ];
        }

        return $resp['body'];
    }

    private function getUserRoles(UserIdentity $user): array
    {
        return \MediaWiki\MediaWikiServices::getInstance()
            ->getUserGroupManager()
            ->getUserGroups($user);
    }
}
