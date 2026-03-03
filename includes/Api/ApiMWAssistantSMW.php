<?php

namespace MWAssistant\Api;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * API endpoint for executing Semantic MediaWiki queries.
 *
 * Responsibilities:
 *  - Authenticate via JWT or session (checkAccess()).
 *  - Validate "query" parameter.
 *  - Execute the SMW #ask query via SMWParserEvaluator.
 *  - Filter results: SMW does not respect Lockdown/ControlAccess read
 *    restrictions, so this endpoint extracts page links from the parser
 *    output and redacts any the user cannot read.
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
        $parserOutput = $evaluator->evaluate($user, $query);
        $html = $parserOutput->getText();

        // -------------------------------------------------------------
        // Permission-filter: SMW ignores Lockdown/ControlAccess, so we
        // check every page link in the output and redact denied ones.
        // -------------------------------------------------------------
        $services = MediaWikiServices::getInstance();
        $permissionManager = $services->getPermissionManager();
        $deniedTitles = [];

        // getLinks() returns [namespace_id => [dbkey => page_id, ...], ...]
        foreach ($parserOutput->getLinks() as $nsId => $pages) {
            foreach ($pages as $dbKey => $pageId) {
                $title = Title::makeTitle((int)$nsId, $dbKey);
                if (!$permissionManager->quickUserCan('read', $user, $title)) {
                    $deniedTitles[] = $title->getPrefixedText();
                }
            }
        }

        if (!empty($deniedTitles)) {
            foreach ($deniedTitles as $denied) {
                $html = str_replace($denied, '[FILTERED]', $html);
            }
        }

        // -------------------------------------------------------------
        // Output result
        // -------------------------------------------------------------
        $this->getResult()->addValue(
            null,
            $this->getModuleName(),
            [
                'result' => $html,
                'filtered_count' => count($deniedTitles),
            ]
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
