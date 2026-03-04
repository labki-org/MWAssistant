<?php

namespace MWAssistant\Api;

use ContentHandler;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * API module for fetching page content with permission checks.
 *
 * This endpoint:
 * 1. Authenticates the request via JWT (trusted MCP server) or session.
 * 2. Resolves the requesting user from username/user_id params.
 * 3. Checks read permission via PermissionManager::quickUserCan().
 * 4. Returns the page wikitext if accessible.
 *
 * Used by the MCP server's get_page_wikitext() to respect Lockdown
 * namespace restrictions for JWT-authenticated requests.
 */
class ApiMWAssistantPage extends ApiMWAssistantBase
{
    /**
     * @inheritDoc
     */
    public function execute(): void
    {
        // 1. Verify Access (JWT with page_read scope, or session)
        $this->checkAccess(['page_read']);

        $params = $this->extractRequestParams();
        $titleStr = $params['title'];
        $username = $params['username'];
        $userId = $params['user_id'];

        // 2. Validate title
        $title = Title::newFromText($titleStr);
        if ($title === null) {
            $this->dieWithError(
                ['apierror-badparams', 'Invalid page title'],
                'bad-title'
            );
        }

        // 3. Resolve the user context
        $user = $this->resolveUser($username, $userId);

        // 4. Check read permission
        $services = MediaWikiServices::getInstance();
        $permissionManager = $services->getPermissionManager();
        $canRead = $permissionManager->quickUserCan('read', $user, $title);

        if (!$canRead) {
            $this->getResult()->addValue(
                null,
                $this->getModuleName(),
                [
                    'exists' => false,
                    'wikitext' => null,
                    'error' => 'permission-denied',
                ]
            );
            return;
        }

        // 5. Fetch page content
        $wikiPage = $services->getWikiPageFactory()->newFromTitle($title);
        $content = $wikiPage->getContent();

        if ($content === null) {
            $this->getResult()->addValue(
                null,
                $this->getModuleName(),
                [
                    'exists' => false,
                    'wikitext' => null,
                ]
            );
            return;
        }

        // 6. Extract wikitext
        $wikitext = ContentHandler::getContentText($content);

        // 7. Get latest revision timestamp
        $revRecord = $services->getRevisionLookup()->getRevisionByTitle($title);
        $timestamp = $revRecord ? $revRecord->getTimestamp() : null;

        $this->getResult()->addValue(
            null,
            $this->getModuleName(),
            [
                'exists' => true,
                'wikitext' => $wikitext,
                'timestamp' => $timestamp,
            ]
        );
    }

    /**
     * @inheritDoc
     */
    public function getAllowedParams(): array
    {
        return [
            'title' => [
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
     * MCP requests are authenticated via JWT, so we do not
     * require a CSRF token (which is for browser sessions).
     *
     * @return bool
     */
    public function needsToken(): bool
    {
        return false;
    }
}
