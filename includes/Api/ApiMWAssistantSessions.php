<?php

namespace MWAssistant\Api;

use MWAssistant\MCP\ChatClient;

/**
 * API endpoint for managing chat sessions.
 *
 * Provides session listing, retrieval, and deletion via the MCP server.
 *
 * Endpoints:
 *   - action=mwassistant-sessions&command=list
 *   - action=mwassistant-sessions&command=get&session_id=...
 *   - action=mwassistant-sessions&command=delete&session_id=...
 */
class ApiMWAssistantSessions extends ApiMWAssistantBase
{

    /**
     * Main API execution.
     *
     * @return void
     */
    public function execute(): void
    {
        // Require chat_completion scope for session management
        $this->checkAccess(['chat_completion']);

        $params = $this->extractRequestParams();
        $command = $params['command'];

        $user = $this->getUser();
        $client = new ChatClient();

        switch ($command) {
            case 'list':
                $result = $client->getSessions($user, $params['limit'] ?? 50, $params['offset'] ?? 0);
                break;
            case 'get':
                $result = $this->handleGet($client, $params);
                break;
            case 'delete':
                $result = $this->handleDelete($client, $params);
                break;
            default:
                $result = ['error' => true, 'message' => 'Unknown command'];
        }

        $this->getResult()->addValue(
            null,
            $this->getModuleName(),
            $result
        );
    }

    /**
     * Handle get session command.
     *
     * @param ChatClient $client
     * @param array $params
     * @return array
     */
    private function handleGet(ChatClient $client, array $params): array
    {
        if (empty($params['session_id'])) {
            return ['error' => true, 'message' => 'Missing session_id parameter'];
        }

        return $client->getSession($this->getUser(), $params['session_id']);
    }

    /**
     * Handle delete session command.
     *
     * @param ChatClient $client
     * @param array $params
     * @return array
     */
    private function handleDelete(ChatClient $client, array $params): array
    {
        if (empty($params['session_id'])) {
            return ['error' => true, 'message' => 'Missing session_id parameter'];
        }

        return $client->deleteSession($this->getUser(), $params['session_id']);
    }

    /**
     * @inheritDoc
     */
    public function getAllowedParams(): array
    {
        return [
            'command' => [
                self::PARAM_TYPE => ['list', 'get', 'delete'],
                self::PARAM_REQUIRED => true,
            ],
            'session_id' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => false,
            ],
            'limit' => [
                self::PARAM_TYPE => 'integer',
                self::PARAM_REQUIRED => false,
                self::PARAM_DFLT => 50,
            ],
            'offset' => [
                self::PARAM_TYPE => 'integer',
                self::PARAM_REQUIRED => false,
                self::PARAM_DFLT => 0,
            ],
        ];
    }

    /**
     * Session management requires a CSRF token for modifications.
     *
     * @return string
     */
    public function needsToken(): string
    {
        return 'csrf';
    }
}
