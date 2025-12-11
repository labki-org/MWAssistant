<?php

namespace MWAssistant\Api;

use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserFactory;
use SearchEngineConfig;
use SearchEngineFactory;

/**
 * API module for performing standard MediaWiki keyword searches on behalf of a user.
 *
 * This endpoint:
 * 1. Authenticates the request via JWT (trusted MCP server).
 * 2. Accepts a 'username' parameter to impersonate the user.
 * 3. Executes a standard search using SearchEngine.
 * 4. Returns rich results including snippets, size, etc.
 */
class ApiMWAssistantKeywordSearch extends ApiMWAssistantBase
{
    /**
     * @inheritDoc
     */
    public function execute(): void
    {

        // 1. Verify Access (JWT or Session)
        // We require 'search' scope for this action if using JWT.
        $this->checkAccess(['search']);

        $params = $this->extractRequestParams();
        $query = $params['query'];
        $limit = $params['limit'];
        $username = $params['username'];

        // 2. Determine the user context
        if ($this->isJwtAuthenticated && $username) {
            // If trusted MCP request, load the user by name
            $userFactory = MediaWikiServices::getInstance()->getUserFactory();
            $user = $userFactory->newFromName($username);

            if (!$user || !$user->isRegistered()) {
                // Fallback to anonymous or error? 
                // For now, let's process as anon if user not found, or maybe error is safer.
                // But arguably the LLM user might not be mapped perfectly. 
                // Let's assume the username passed is valid.
                // If invalid, $user will be false or anon.
                if (!$user) {
                    $user = UserFactory::newAnonymous();
                }
            }
        } else {
            // Session auth or no username provided -> use current user
            $user = $this->getUser();
        }

        // 3. Perform the search
        $results = $this->performSearch($user, $query, $limit);

        // 4. Return results
        $this->getResult()->addValue(null, $this->getModuleName(), $results);
    }

    /**
     * Execute the search using MediaWiki's SearchEngine.
     *
     * @param UserIdentity $user
     * @param string $query
     * @param int $limit
     * @return array
     */
    private function performSearch(UserIdentity $user, string $query, int $limit): array
    {
        $services = MediaWikiServices::getInstance();
        $searchEngineFactory = $services->getSearchEngineFactory();
        $searchEngine = $searchEngineFactory->create();

        // Set limit and offset
        $searchEngine->setLimitOffset($limit, 0);

        // Perform the search
        // We search in default namespaces typically (Main). 
        // We could expose namespaces as a param later if needed.
        $searchEngine->setNamespaces([NS_MAIN]);

        $matches = $searchEngine->searchText($query);

        $out = [];
        if ($matches) {
            foreach ($matches as $result) {
                // $result is a SearchResult object
                $title = $result->getTitle();

                if (!$title) {
                    continue;
                }

                if (!$services->getPermissionManager()->userCan('read', $user, $title)) {
                    continue;
                }

                $out[] = [
                    'title' => $title->getPrefixedText(),
                    'snippet' => $result->getTextSnippet($matches),
                    'size' => $result->getByteSize(),
                    'wordcount' => $result->getWordCount(),
                    'timestamp' => $result->getTimestamp(),
                ];
            }
        }

        return $out;
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
            'limit' => [
                self::PARAM_TYPE => 'integer',
                self::PARAM_DFLT => 10,
                self::PARAM_MIN => 1,
                self::PARAM_MAX => 50,
            ],
            'username' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => false,
            ],
        ];
    }
}
