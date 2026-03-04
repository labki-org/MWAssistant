<?php

namespace MWAssistant\Api;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * API module for listing category members with permission checks.
 *
 * This endpoint:
 * 1. Authenticates via JWT (MCP server) or session.
 * 2. Resolves the requesting user from username/user_id params.
 * 3. Filters results by read permission on each member page.
 * 4. Returns the list of accessible category members.
 *
 * Used by the MCP server's get_category_members() on private wikis
 * where the standard action=query API returns readapidenied.
 */
class ApiMWAssistantCategoryMembers extends ApiMWAssistantBase
{
    /**
     * @inheritDoc
     */
    public function execute(): void
    {
        $this->checkAccess(['page_read']);

        $params = $this->extractRequestParams();
        $category = $params['category'];
        $limit = $params['limit'];
        $username = $params['username'];
        $userId = $params['user_id'];

        // Ensure Category: prefix
        if (strpos($category, 'Category:') !== 0) {
            $category = 'Category:' . $category;
        }

        $title = Title::newFromText($category);
        if ($title === null) {
            $this->dieWithError(
                ['apierror-badparams', 'Invalid category title'],
                'bad-title'
            );
        }

        $user = $this->resolveUser($username, $userId);
        $services = MediaWikiServices::getInstance();
        $permissionManager = $services->getPermissionManager();

        // Use CategoryMemberQuery via the DB
        $dbr = $services->getDBLoadBalancer()->getConnection(DB_REPLICA);
        $res = $dbr->select(
            'categorylinks',
            ['cl_from'],
            ['cl_to' => $title->getDBkey()],
            __METHOD__,
            ['LIMIT' => $limit, 'ORDER BY' => 'cl_sortkey']
        );

        $members = [];
        foreach ($res as $row) {
            $memberTitle = Title::newFromID($row->cl_from);
            if ($memberTitle === null) {
                continue;
            }

            // Filter by read permission
            if (!$permissionManager->quickUserCan('read', $user, $memberTitle)) {
                continue;
            }

            $members[] = [
                'title' => $memberTitle->getPrefixedText(),
                'ns' => $memberTitle->getNamespace(),
                'pageid' => (int)$row->cl_from,
            ];
        }

        $this->getResult()->addValue(
            null,
            $this->getModuleName(),
            ['members' => $members]
        );
    }

    /**
     * @inheritDoc
     */
    public function getAllowedParams(): array
    {
        return [
            'category' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => true,
            ],
            'limit' => [
                self::PARAM_TYPE => 'integer',
                self::PARAM_REQUIRED => false,
                self::PARAM_DFLT => 50,
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
