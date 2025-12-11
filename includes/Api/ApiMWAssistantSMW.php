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

        // -------------------------------------------------------------
        // Validate parameter
        // -------------------------------------------------------------
        if (!is_string($query) || trim($query) === '') {
            $this->dieWithError(
                ['apierror-badparams', 'SMW query cannot be empty.'],
                'query'
            );
        }

        $user = $this->getUser();

        // -------------------------------------------------------------
        // Execute query via MCP backend
        // -------------------------------------------------------------
        $client = new SMWClient();
        $result = $client->query($user, $query);

        // -------------------------------------------------------------
        // Output result
        // -------------------------------------------------------------
        $this->getResult()->addValue(
            null,
            $this->getModuleName(),
            $result
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
        ];
    }

    /**
     * SMW queries require a CSRF token when invoked from the UI.
     *
     * @return string
     */
    public function needsToken(): string
    {
        return 'csrf';
    }
}
