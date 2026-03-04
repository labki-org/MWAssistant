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
    private const ALLOWED_ROLES = ['user', 'assistant', 'system'];
    private const ALLOWED_CONTEXTS = ['chat', 'editor'];

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

        // Validate session_id is a valid UUID v4 if provided
        if ($sessionId !== null && $sessionId !== '') {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sessionId)) {
                $this->dieWithError(
                    ['apierror-badparams', 'Invalid session_id format (expected UUID v4)'],
                    'bad-session-id'
                );
            }
        }

        // Validate each message has required fields with valid values
        foreach ($messages as $i => $msg) {
            if (!is_array($msg)) {
                $this->dieWithError(
                    ['apierror-badparams', "Message at index $i is not an object"],
                    'bad-message'
                );
            }
            if (!isset($msg['role']) || !in_array($msg['role'], self::ALLOWED_ROLES, true)) {
                $this->dieWithError(
                    ['apierror-badparams', "Message at index $i has invalid or missing role"],
                    'bad-message-role'
                );
            }
            if (!isset($msg['content']) || !is_string($msg['content'])) {
                $this->dieWithError(
                    ['apierror-badparams', "Message at index $i has invalid or missing content"],
                    'bad-message-content'
                );
            }
        }

        // Validate context parameter
        if (!in_array($context, self::ALLOWED_CONTEXTS, true)) {
            $this->dieWithError(
                ['apierror-badparams', 'Invalid context parameter (expected "chat" or "editor")'],
                'bad-context'
            );
        }

        // -------------------------------------------------------------
        // Invoke the MCP chat backend
        // -------------------------------------------------------------
        $user = $this->resolveUser();
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
