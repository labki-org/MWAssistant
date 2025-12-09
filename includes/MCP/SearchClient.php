<?php

namespace MWAssistant\MCP;

use MWAssistant\HttpClient;
use MWAssistant\JWT;
use MediaWiki\User\UserIdentity;
use MediaWiki\MediaWikiServices;

class SearchClient
{

    private HttpClient $client;

    public function __construct()
    {
        $this->client = new HttpClient();
    }

    public function search(UserIdentity $user, string $query): array
    {
        $roles = $this->getUserRoles($user);
        $jwt = JWT::createForUser($user, $roles);

        $payload = [
            'query' => $query,
        ];

        $resp = $this->client->postJson('/search/', $payload, $jwt);

        if (!$resp['ok']) {
            return [
                'error' => true,
                'status' => $resp['code'],
                'message' => 'MCP search error: ' . (is_string($resp['body']) ? $resp['body'] : json_encode($resp['body']))
            ];
        }

        return $resp['body'];
    }

    private function getUserRoles(UserIdentity $user): array
    {
        $userObj = MediaWikiServices::getInstance()
            ->getUserFactory()
            ->newFromUserIdentity($user);

        return $userObj->getGroups();
    }
}
