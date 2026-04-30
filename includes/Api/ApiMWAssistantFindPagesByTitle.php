<?php

namespace MWAssistant\Api;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;

/**
 * Permission-aware title-prefix lookup.
 *
 * Mirrors `list=allpages&apprefix=` but works on private wikis: standard
 * `list=allpages` rejects anonymous requests with `readapidenied` even with
 * a valid JWT, because the API framework's read check runs before our auth.
 * This module wraps the same SQL with our JWT-or-session auth and filters
 * results through MediaWiki's permission manager so namespace-locked or
 * page-locked rows are dropped.
 */
class ApiMWAssistantFindPagesByTitle extends ApiMWAssistantBase
{
    /**
     * @inheritDoc
     */
    public function execute(): void
    {
        $this->checkAccess(['page_read']);

        $params = $this->extractRequestParams();
        $prefix = $params['prefix'];
        $namespace = (int)$params['namespace'];
        $limit = (int)$params['limit'];
        $username = $params['username'] ?? null;
        $userId = $params['user_id'] ?? null;

        $user = $this->resolveUser($username, $userId);
        $rows = $this->lookup($user, $prefix, $namespace, $limit);

        $this->getResult()->addValue(null, $this->getModuleName(), [
            'pages' => $rows,
        ]);
    }

    /**
     * @return array{title:string,ns:int,pageid:int}[]
     */
    private function lookup(UserIdentity $user, string $prefix, int $namespace, int $limit): array
    {
        $services = MediaWikiServices::getInstance();
        $dbr = $services->getConnectionProvider()->getReplicaDatabase();
        $permManager = $services->getPermissionManager();

        // Match list=allpages semantics: case-sensitive prefix on page_title
        // (where titles are stored with underscores). Convert spaces to
        // underscores so callers can pass either form.
        $dbPrefix = strtr($prefix, ' ', '_');

        $rows = $dbr->newSelectQueryBuilder()
            ->select(['page_id', 'page_title', 'page_namespace'])
            ->from('page')
            ->where([
                'page_namespace' => $namespace,
                $dbr->expr('page_title', '>=', $dbPrefix),
                $dbr->expr(
                    'page_title',
                    '<',
                    $dbPrefix . "\xFF"
                ),
            ])
            ->orderBy('page_title')
            // Over-fetch a bit so post-filter doesn't undershoot the limit.
            ->limit(min(max($limit * 2, $limit), 1000))
            ->caller(__METHOD__)
            ->fetchResultSet();

        $out = [];
        foreach ($rows as $row) {
            // Defensive: the BETWEEN trick can rarely overshoot due to
            // collation quirks; re-confirm the prefix in PHP.
            if (strncmp($row->page_title, $dbPrefix, strlen($dbPrefix)) !== 0) {
                continue;
            }

            $title = Title::makeTitle($row->page_namespace, $row->page_title);
            if (!$title || !$permManager->quickUserCan('read', $user, $title)) {
                continue;
            }

            $out[] = [
                'title' => $title->getPrefixedText(),
                'ns' => (int)$row->page_namespace,
                'pageid' => (int)$row->page_id,
            ];
            if (count($out) >= $limit) {
                break;
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
            'prefix' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => true,
            ],
            'namespace' => [
                self::PARAM_TYPE => 'integer',
                self::PARAM_DFLT => 0,
            ],
            'limit' => [
                self::PARAM_TYPE => 'integer',
                self::PARAM_DFLT => 50,
                self::PARAM_MIN => 1,
                self::PARAM_MAX => 500,
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
}
