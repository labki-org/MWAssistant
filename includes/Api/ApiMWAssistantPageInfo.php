<?php

namespace MWAssistant\Api;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * API module for fetching lightweight page metadata with permission checks.
 *
 * Returns page existence, namespace, size, and last modified timestamp
 * without fetching full content. Used by the MCP server's get_page_info()
 * on private wikis where the standard action=query API returns readapidenied.
 */
class ApiMWAssistantPageInfo extends ApiMWAssistantBase
{
    /**
     * @inheritDoc
     */
    public function execute(): void
    {
        $this->checkAccess(['page_read']);

        $params = $this->extractRequestParams();
        $titleStr = $params['title'];
        $username = $params['username'];
        $userId = $params['user_id'];

        $title = Title::newFromText($titleStr);
        if ($title === null) {
            $this->dieWithError(
                ['apierror-badparams', 'Invalid page title'],
                'bad-title'
            );
        }

        $user = $this->resolveUser($username, $userId);
        $services = MediaWikiServices::getInstance();
        $permissionManager = $services->getPermissionManager();

        // Check read permission
        if (!$permissionManager->quickUserCan('read', $user, $title)) {
            $this->getResult()->addValue(
                null,
                $this->getModuleName(),
                [
                    'exists' => false,
                    'error' => 'permission-denied',
                ]
            );
            return;
        }

        // Check existence
        if (!$title->exists()) {
            $this->getResult()->addValue(
                null,
                $this->getModuleName(),
                ['exists' => false]
            );
            return;
        }

        $wikiPage = $services->getWikiPageFactory()->newFromTitle($title);
        $revRecord = $services->getRevisionLookup()->getRevisionByTitle($title);

        $this->getResult()->addValue(
            null,
            $this->getModuleName(),
            [
                'exists' => true,
                'title' => $title->getPrefixedText(),
                'pageid' => $title->getArticleID(),
                'ns' => $title->getNamespace(),
                'length' => $title->getLength(),
                'timestamp' => $revRecord ? $revRecord->getTimestamp() : null,
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
     * @return bool
     */
    public function needsToken(): bool
    {
        return false;
    }
}
