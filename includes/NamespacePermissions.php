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
     * Get the list of namespace IDs that a user can read.
     *
     * @param UserIdentity $user
     * @return int[]
     */
    public static function getReadableNamespaces(UserIdentity $user): array
    {
        $services = MediaWikiServices::getInstance();
        $permissionManager = $services->getPermissionManager();
        $namespaceInfo = $services->getNamespaceInfo();

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

            if ($permissionManager->userCan('read', $user, $testTitle)) {
                $readable[] = $nsId;
            }
        }

        return $readable;
    }

    /**
     * Check if a user can read a specific namespace.
     *
     * @param UserIdentity $user
     * @param int $namespaceId
     * @return bool
     */
    public static function canReadNamespace(UserIdentity $user, int $namespaceId): bool
    {
        $services = MediaWikiServices::getInstance();
        $permissionManager = $services->getPermissionManager();

        $testTitle = Title::makeTitle($namespaceId, 'MWAssistant_NamespaceCheck_Dummy');

        return $permissionManager->userCan('read', $user, $testTitle);
    }
}
