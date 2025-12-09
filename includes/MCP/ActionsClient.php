<?php

namespace MWAssistant\MCP;

use MWAssistant\HttpClient;
use MWAssistant\JWT;
use MediaWiki\User\UserIdentity;
use MediaWiki\MediaWikiServices;

class ActionsClient
{

    private HttpClient $client;

    public function __construct()
    {
        $this->client = new HttpClient();
    }

    public function editPage(UserIdentity $user, string $title, string $content, string $summary = ''): array
    {
        $roles = $this->getUserRoles($user);
        $jwt = JWT::createForUser($user, $roles);

        $payload = [
            'title' => $title,
            'content' => $content,
            'summary' => $summary
        ];

        $resp = $this->client->postJson('/actions/edit-page', $payload, $jwt);

        if (!$resp['ok']) {
            return [
                'error' => true,
                'status' => $resp['code'],
                'message' => 'MCP action error: ' . (is_string($resp['body']) ? $resp['body'] : json_encode($resp['body']))
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
