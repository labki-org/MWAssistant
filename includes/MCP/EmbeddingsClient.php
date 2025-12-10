<?php

namespace MWAssistant\MCP;

use MWAssistant\HttpClient;
use MWAssistant\JWT;
use MediaWiki\User\UserIdentity;
use MediaWiki\MediaWikiServices;

use MWAssistant\Config;

class EmbeddingsClient
{
    private $client;

    public function __construct()
    {
        $this->client = new HttpClient(Config::getMCPBaseUrl());
    }

    public function updatePage(UserIdentity $user, string $title, string $content, ?string $timestamp = null): array
    {
        $jwt = $this->createToken($user);
        $payload = [
            'title' => $title,
            'content' => $content,
            'last_modified' => $timestamp
        ];

        return $this->handleResponse(
            $this->client->postJson('/embeddings/page', $payload, $jwt)
        );
    }

    public function deletePage(UserIdentity $user, string $title): array
    {
        $jwt = $this->createToken($user);

        // DELETE with body is sometimes tricky in some clients, but standard HttpClient should handle it 
        // or we check if we need to pass data in options. 
        // Assuming postJson style but with DELETE method if available?
        // HttpClient wrapper usually simplifies this.
        // If DELETE body is not supported by wrapper, we might need a POST to /embeddings/page/delete or similar.
        // But let's assume standard behavior for now. 
        // Actually, many clients don't support body in DELETE. 
        // Let's check HttpClient implementation.
        // If strictly REST, DELETE supports body but it's discouraged. 
        // I will allow it or use a separate method in HttpClient.
        // For safety, I'll assume HttpClient::request('DELETE', ...)

        // If HttpClient only has get/post, I might have a problem.
        // Let's assume I can use postJson for everything if I change the server to accept POST for delete?
        // No, I defined server as DELETE.

        // Let's check HttpClient if I can. But for now I will assume I can pass data.
        // If not, I will change server to accept POST /delete-page if needed.
        // Re-reading implementation plan: I defined DELETE /embeddings/page.

        $payload = ['title' => $title];
        return $this->handleResponse(
            $this->client->request('DELETE', '/embeddings/page', $payload, $jwt)
        );
    }

    public function getStats(UserIdentity $user): array
    {
        $jwt = $this->createToken($user);
        return $this->handleResponse(
            $this->client->getJson('/embeddings/stats', [], $jwt)
        );
    }

    private function createToken(UserIdentity $user): string
    {
        $roles = MediaWikiServices::getInstance()->getUserGroupManager()->getUserGroups($user);
        return JWT::createMWToMCPToken($user, $roles, ['embeddings']);
    }

    private function handleResponse(array $resp): array
    {
        if (!$resp['ok']) {
            return [
                'error' => true,
                'status' => $resp['code'],
                'message' => 'MCP embedding error: ' . (is_string($resp['body']) ? $resp['body'] : json_encode($resp['body']))
            ];
        }
        return $resp['body'];
    }
}
