<?php

namespace MWAssistant\Api;

use MWAssistant\MCP\ChatClient;

/**
 * API endpoint for submitting chat messages to the MCP server
 * and returning model responses.
 *
 * Responsibilities:
 *  - Authenticate (JWT or session) using inherited checkAccess().
 *  - Validate & parse the incoming message payload.
 *  - Forward conversation state to ChatClient.
 *
 * Note: Chat persistence is now handled by the MCP server's PostgreSQL database.
 */
class ApiMWAssistantChat extends ApiMWAssistantBase
{

    /**
     * Main API execution.
     *
     * @return void
     */
    public function execute(): void
    {
        // Require the JWT scope "chat_completion" if authenticated via JWT.
        $this->checkAccess(['chat_completion']);

        $params = $this->extractRequestParams();

        // -------------------------------------------------------------
        // Parameter validation
        // -------------------------------------------------------------
        $messages = json_decode($params['messages'], true);
        $sessionId = $params['session_id'] ?? null;
        $context = $params['context'] ?? 'chat';

        if (!is_array($messages)) {
            $this->dieWithError(
                ['apierror-badparams', 'Invalid messages parameter'],
                'messages'
            );
        }

        // -------------------------------------------------------------
        // Invoke the MCP chat backend
        // -------------------------------------------------------------
        $user = $this->getUser();
        $client = new ChatClient();
        $response = $client->chat($user, $messages, $sessionId, $context);

        // -------------------------------------------------------------
        // Output result
        // -------------------------------------------------------------
        $this->getResult()->addValue(
            null,
            $this->getModuleName(),
            $response
        );
    }

    /**
     * @inheritDoc
     */
    public function getAllowedParams(): array
    {
        return [
            'messages' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => true,
            ],
            'session_id' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => false,
            ],
            'context' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => false, // Default handled in execute()
            ],
        ];
    }

    /**
     * Chat actions require a CSRF token when invoked from the UI.
     *
     * @return string
     */
    public function needsToken(): string
    {
        return 'csrf';
    }
}
