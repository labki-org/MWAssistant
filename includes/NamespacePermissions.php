<?php

namespace MWAssistant;

use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;

/**
 * Helper class to resolve which namespaces a user can read.
 *
 * This is used to populate the JWT with allowed_namespaces, enabling
 * fast pre-filtering on the MCP server before expensive permission checks.
 *
 * Respects:
 * - Lockdown namespace restrictions
 * - Default MediaWiki namespace permissions
 */
class NamespacePermissions
{
    /**
     * Upper bound on memoized users, so long-running CLI processes (job
     * runners) don't grow the cache without limit.
     */
    private const MAX_CACHE_ENTRIES = 100;

    /** @var array<string,int[]> Readable namespace IDs, keyed per user */
    private static array $cache = [];

    /**
     * Get the list of namespace IDs that a user can read.
     *
     * Memoized per user for the lifetime of the process (i.e. per web
     * request): a single request can mint several JWTs, and each would
     * otherwise rerun the permission check for every namespace.
     *
     * Uses RIGOR_QUICK, matching the per-page read checks in the API modules.
     * Quick rigor still runs the getUserPermissionsErrors hook, so Lockdown
     * restrictions are respected.
     *
     * @param UserIdentity $user
     * @return int[]
     */
    public static function getReadableNamespaces(UserIdentity $user): array
    {
        $key = $user->isRegistered() ? 'id:' . $user->getId() : 'name:' . $user->getName();
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $services = MediaWikiServices::getInstance();
        $permissionManager = $services->getPermissionManager();
        $namespaceInfo = $services->getNamespaceInfo();
        // PermissionManager::userCan() needs a full User, not just an identity.
        $user = $services->getUserFactory()->newFromUserIdentity($user);

        // Get all valid namespace IDs
        $allNamespaces = $namespaceInfo->getCanonicalNamespaces();

        $readable = [];

        foreach ($allNamespaces as $nsId => $nsName) {
            // Skip special namespaces (negative IDs like -1 for Special:)
            if ($nsId < 0) {
                continue;
            }

            // Check if user can read in this namespace by testing with a dummy title
            // We use a non-existent page to avoid false negatives from page-specific restrictions
            $testTitle = Title::makeTitle($nsId, 'MWAssistant_NamespaceCheck_Dummy');

            if ($permissionManager->userCan('read', $user, $testTitle, PermissionManager::RIGOR_QUICK)) {
                $readable[] = $nsId;
            }
        }

        if (count(self::$cache) >= self::MAX_CACHE_ENTRIES) {
            self::$cache = [];
        }
        self::$cache[$key] = $readable;

        return $readable;
    }

    /**
     * Drop memoized results (e.g. after changing permissions in tests).
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
