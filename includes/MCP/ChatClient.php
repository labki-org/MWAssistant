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

    public function chat(UserIdentity $user, array $messages): array
    {
        $roles = $this->getUserRoles($user); // You'll need to implement role extraction or use simple groups
        $jwt = JWT::createForUser($user, $roles);

        $payload = [
            'messages' => $messages,
            'max_tokens' => 512,
        ];

        $secret = \MWAssistant\Config::getJWTSecret();
        \wfDebugLog('mwassistant', 'Config check - Secret length: ' . strlen($secret) . ', Base URL: ' . \MWAssistant\Config::getMCPBaseUrl());

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
