<?php

namespace MWAssistant\Api;

use MWAssistant\Api\ApiMWAssistantBase;
use MWAssistant\MCP\SMWClient;

/**
 * API endpoint for executing Semantic MediaWiki queries
 * through the MCP server.
 *
 * Responsibilities:
 *  - Authenticate via JWT or session (checkAccess()).
 *  - Validate "query" parameter.
 *  - Forward SMW query text to SMWClient.
 *  - Return structured results to the caller.
 *
 * NOTE:
 * SMW queries may reference internal wiki structures; ensure
 * SMWClient enforces permission checks and safe query execution.
 */
class ApiMWAssistantSMW extends ApiMWAssistantBase
{

    /**
     * Execute the SMW query request.
     *
     * @return void
     */
    public function execute(): void
    {
        // Require JWT scope "smw_query" when authenticated via JWT.
        $this->checkAccess(['smw_query']);

        $params = $this->extractRequestParams();
        $query = $params['query'] ?? '';
        $username = $params['username'] ?? null;
        $userId = $params['user_id'] ?? null;

        // -------------------------------------------------------------
        // Validate parameter
        // -------------------------------------------------------------
        if (!is_string($query) || trim($query) === '') {
            $this->dieWithError(
                ['apierror-badparams', 'SMW query cannot be empty.'],
                'query'
            );
        }

        $user = $this->resolveUser($username, $userId);

        // -------------------------------------------------------------
        // Execute query via Parser Evaluator
        // -------------------------------------------------------------
        $evaluator = new \MWAssistant\SMW\SMWParserEvaluator();
        $output = $evaluator->evaluate($user, $query);

        // -------------------------------------------------------------
        // Output result
        // -------------------------------------------------------------
        $this->getResult()->addValue(
            null,
            $this->getModuleName(),
            ['result' => $output]
        );
    }

    /**
     * @inheritDoc
     */
    public function getAllowedParams(): array
    {
        return [
            'query' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => true,
            ],
            'username' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => false,
            ],
            'user_id' => [
                self::PARAM_TYPE => 'integer',
                self::PARAM_REQUIRED => false,
            ],
        ];
    }

    /**
     * SMW queries from MCP are authenticated via JWT, so we do not
     * require a CSRF token (which is for browser sessions).
     *
     * @return bool
     */
    public function needsToken(): bool
    {
        return false;
    }
}
