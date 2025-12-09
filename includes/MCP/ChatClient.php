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

        $resp = $this->client->postJson('/chat/', $payload, $jwt);

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
        // Simple implementation: getting groups.
        // In a real scenario, you might want to map these to specific MCP roles.
        // For now, we assume the user object we have is a User object which has getGroups(), 
        // but UserIdentity doesn't have getGroups(). We need to convert or assume it's a User.
        // The calling code (API) likely has the User object.
        // But to be safe let's try to load the user if it's just an identity, 
        // or rely on the caller passing a User object.
        // Since we are inside MediaWiki, we can use the UserFactory service 
        // or just accept that $user might be a User object.

        // Actually, let's use the UserFactory to be safe if we only have UserIdentity
        // But explicit type hit in signature says UserIdentity.

        $userObj = \MediaWiki\MediaWikiServices::getInstance()
            ->getUserFactory()
            ->newFromUserIdentity($user);

        return $userObj->getGroups();
    }
}
