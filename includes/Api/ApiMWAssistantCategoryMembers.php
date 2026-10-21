<?php

namespace MWAssistant\Api;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\IResultWrapper;

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
    /** @var int Upper bound on the number of members returned per call */
    private const MAX_LIMIT = 500;

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
        if ($title === null || $title->getNamespace() !== NS_CATEGORY) {
            $this->dieWithError(
                ['apierror-mwassistant-badparams', 'Invalid category title'],
                'bad-title'
            );
        }

        $services = MediaWikiServices::getInstance();
        $permissionManager = $services->getPermissionManager();
        // PermissionManager::quickUserCan() needs a full User.
        $user = $services->getUserFactory()->newFromUserIdentity(
            $this->resolveUser($username, $userId)
        );

        $dbr = $services->getConnectionProvider()->getReplicaDatabase();
        $res = $this->fetchMembers($dbr, $title->getDBkey(), $limit);

        $members = [];
        foreach ($res as $row) {
            $memberTitle = Title::makeTitle((int)$row->page_namespace, $row->page_title);

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
     * Fetch member pages of a category, ordered by sort key.
     *
     * MW 1.45+ normalized categorylinks onto linktarget (cl_target_id) and
     * MW 1.46 dropped cl_to. On 1.44 the migration stage still defaults to
     * the old schema, so fall back to cl_to when the wiki isn't reading new.
     */
    private function fetchMembers(IReadableDatabase $dbr, string $dbKey, int $limit): IResultWrapper
    {
        $qb = $dbr->newSelectQueryBuilder()
            ->select(['cl_from', 'page_namespace', 'page_title'])
            ->from('categorylinks')
            ->join('page', null, 'page_id = cl_from')
            ->orderBy('cl_sortkey')
            ->limit($limit)
            ->caller(__METHOD__);

        $config = $this->getConfig();
        $readOld = $config->has('CategoryLinksSchemaMigrationStage')
            && !($config->get('CategoryLinksSchemaMigrationStage') & SCHEMA_COMPAT_READ_NEW);

        if ($readOld) {
            $qb->where(['cl_to' => $dbKey]);
        } else {
            $qb->join('linktarget', null, 'lt_id = cl_target_id')
                ->where(['lt_namespace' => NS_CATEGORY, 'lt_title' => $dbKey]);
        }

        return $qb->fetchResultSet();
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
                self::PARAM_MIN => 1,
                self::PARAM_MAX => self::MAX_LIMIT,
                self::PARAM_MAX2 => self::MAX_LIMIT,
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
