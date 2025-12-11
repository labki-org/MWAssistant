<?php

namespace MWAssistant\Api;

use MWAssistant\Api\ApiMWAssistantBase;
use MWAssistant\MCP\SearchClient;

/**
 * API endpoint for sending search queries to the MCP server.
 *
 * Responsibilities:
 *  - Authenticate via inherited checkAccess() (JWT or session).
 *  - Validate incoming "query" input.
 *  - Forward search requests to SearchClient.
 *  - Return structured search results.
 */
class ApiMWAssistantSearch extends ApiMWAssistantBase
{

    /**
     * Execute the search request.
     *
     * @return void
     */
    public function execute(): void
    {
        // Require JWT scope "search" when JWT-authenticated.
        $this->checkAccess(['search']);

        $params = $this->extractRequestParams();
        $query = $params['query'] ?? '';

        // -------------------------------------------------------------
        // Validate query parameter
        // -------------------------------------------------------------
        if (!is_string($query) || trim($query) === '') {
            $this->dieWithError(
                ['apierror-badparams', 'Search query cannot be empty.'],
                'query'
            );
        }

        $user = $this->getUser();

        // -------------------------------------------------------------
        // Send to MCP search backend
        // -------------------------------------------------------------
        $client = new SearchClient();
        $result = $client->search($user, $query);

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
     * Searches require a CSRF token when invoked from the UI.
     *
     * @return string
     */
    public function needsToken(): string
    {
        return 'csrf';
    }
}
