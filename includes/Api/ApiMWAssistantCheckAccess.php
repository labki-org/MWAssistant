<?php

namespace MWAssistant\Api;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;

/**
 * API module for batch checking read permissions on pages.
 *
 * This endpoint:
 * 1. Authenticates the request via JWT (trusted MCP server).
 * 2. Accepts a 'username' parameter to impersonate the user.
 * 3. Accepts a 'titles' parameter (pipe-separated page titles).
 * 4. Returns a map of title => boolean indicating read permission.
 *
 * Used by the MCP server to validate vector search results against
 * the user's actual permissions (Lockdown, ControlAccess, etc.).
 */
class ApiMWAssistantCheckAccess extends ApiMWAssistantBase
{
    /**
     * @inheritDoc
     */
    public function execute(): void
    {
        // 1. Verify Access (JWT required with check_access scope)
        $this->checkAccess(['check_access']);

        $params = $this->extractRequestParams();
        $titlesRaw = $params['titles'];
        $username = $params['username'];

        // 2. Parse titles (pipe-separated, like standard MW API)
        $titleStrings = array_filter(
            array_map('trim', explode('|', $titlesRaw)),
            fn($t) => $t !== ''
        );

        if (empty($titleStrings)) {
            $this->dieWithError(
                ['apierror-badparams', 'titles cannot be empty'],
                'no-titles'
            );
        }

        // Hard limit to prevent abuse
        if (count($titleStrings) > 100) {
            $this->dieWithError(
                ['apierror-badparams', 'Maximum 100 titles allowed per request'],
                'too-many-titles'
            );
        }

        // 3. Resolve the user context
        $user = $this->resolveUser($username);

        // 4. Check permissions for each title
        $access = $this->checkPermissions($user, $titleStrings);

        // 5. Return results
        $this->getResult()->addValue(
            null,
            $this->getModuleName(),
            ['access' => $access]
        );
    }

    /**
     * Resolve the user to check permissions for.
     *
     * @param string|null $username
     * @return UserIdentity
     */
    private function resolveUser(?string $username): UserIdentity
    {
        if ($this->isJwtAuthenticated && $username) {
            $userFactory = MediaWikiServices::getInstance()->getUserFactory();
            $user = $userFactory->newFromName($username);

            if ($user && $user->isRegistered()) {
                return $user;
            }

            // Fall back to anonymous if user not found
            return $userFactory->newAnonymous();
        }

        // Session auth or no username provided -> use current user
        return $this->getUser();
    }

    /**
     * Check read permissions for a list of page titles.
     *
     * @param UserIdentity $user
     * @param string[] $titleStrings
     * @return array<string, bool>
     */
    private function checkPermissions(UserIdentity $user, array $titleStrings): array
    {
        $services = MediaWikiServices::getInstance();
        $permissionManager = $services->getPermissionManager();

        $access = [];

        foreach ($titleStrings as $titleStr) {
            $title = Title::newFromText($titleStr);

            if ($title === null) {
                // Invalid title syntax
                $access[$titleStr] = false;
                continue;
            }

            // Check if user can read this page
            $access[$titleStr] = $permissionManager->userCan('read', $user, $title);
        }

        return $access;
    }

    /**
     * @inheritDoc
     */
    public function getAllowedParams(): array
    {
        return [
            'titles' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => true,
            ],
            'username' => [
                self::PARAM_TYPE => 'string',
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
