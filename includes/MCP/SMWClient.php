<?php

namespace MWAssistant\MCP;

use MWAssistant\HttpClient;
use MWAssistant\JWT;
use MediaWiki\User\UserIdentity;
use MediaWiki\MediaWikiServices;

class SMWClient
{

    private HttpClient $client;

    public function __construct()
    {
        $this->client = new HttpClient();
    }

    public function query(UserIdentity $user, string $description): array
    {
        $roles = $this->getUserRoles($user);
        $jwt = JWT::createMWToMCPToken($user, $roles, ['smw_query']);


        $payload = [
            'query' => $description, // The user prompt describing the query
        ];

        // Adjusted endpoint to match /smw-query/ from spec, assuming it takes 'query' or 'description'
        // Spec said: "only the query body, no explanation"
        $resp = $this->client->postJson('/smw-query/', $payload, $jwt);

        if (!$resp['ok']) {
            return [
                'error' => true,
                'status' => $resp['code'],
                'message' => 'MCP SMW query error: ' . (is_string($resp['body']) ? $resp['body'] : json_encode($resp['body']))
            ];
        }

        return $resp['body'];
    }

    private function getUserRoles(UserIdentity $user): array
    {
        return MediaWikiServices::getInstance()
            ->getUserGroupManager()
            ->getUserGroups($user);
    }
}
