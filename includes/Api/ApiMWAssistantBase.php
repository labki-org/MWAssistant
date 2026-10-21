<?php

namespace MWAssistant\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use MediaWiki\Request\WebRequest;
use MWAssistant\JWTVerifier;

/**
 * Base class for all MWAssistant API endpoints.
 *
 * Responsibilities:
 *  - Provide a unified access-control flow for all API modules.
 *  - Support either:
 *        (a) JWT-based authentication (calls originating from the MCP server), or
 *        (b) Normal MediaWiki session-based authentication (user in browser).
 *  - Expose $isJwtAuthenticated so submodules can branch behavior if needed.
 *
 * Future testability notes:
 *  - This class avoids hard dependencies on global state besides ApiBase facilities.
 *  - API test classes can directly exercise checkAccess() via faux request objects.
 */
abstract class ApiMWAssistantBase extends ApiBase
{

    /** @var bool Whether the request was authenticated using a JWT */
    protected bool $isJwtAuthenticated = false;

    /** @var array Verified JWT claims (empty unless JWT-authenticated) */
    protected array $jwtClaims = [];

    /**
     * Validates that the caller has sufficient authorization.
     *
     * JWT authentication:
     * --------------------
     * - Expected when the request includes:
     *       Authorization: Bearer <JWT>
     * - Token is validated with JWTVerifier::verifyMCPToMWToken().
     * - If validation succeeds, no MediaWiki permissions are checked.
     *
     * Session authentication:
     * -----------------------
     * - Triggered when no JWT is present.
     * - Requires the user to have the 'mwassistant-use' permission.
     *
     * @param array $requiredScopes Scopes that must be present if JWT authentication is used.
     *
     * @return void
     */
    protected function checkAccess(array $requiredScopes): void
    {
        $request = $this->getRequest();
        $authHeader = $request->getHeader('Authorization');

        // -------------------------------------------------------
        // 1. JWT AUTHENTICATION PATH
        // -------------------------------------------------------
        if ($this->looksLikeBearerToken($authHeader)) {
            $token = substr($authHeader, 7);

            $verifier = new JWTVerifier();
            $payload = $verifier->verifyMCPToMWToken($token, $requiredScopes);

            if (!$payload) {
                // Avoid revealing specific token validation details.
                $this->dieWithError(
                    ['apierror-mwassistant-invalid-jwt', 'wikitext'],
                    'invalid_jwt',
                    [],
                    403
                );
            }

            $this->isJwtAuthenticated = true;
            $this->jwtClaims = $payload;
            return;
        }

        // -------------------------------------------------------
        // 2. SESSION AUTHENTICATION PATH
        // -------------------------------------------------------
        $this->assertSessionAccess();
    }

    /**
     * Returns true if the header appears to be a Bearer token.
     *
     * @param string|null $authHeader
     * @return bool
     */
    private function looksLikeBearerToken(?string $authHeader): bool
    {
        if (!$authHeader) {
            return false;
        }

        // Case-insensitive check for safety, though MW sends as "Bearer ".
        return stripos($authHeader, 'Bearer ') === 0;
    }

    /**
     * Resolve the user to act on behalf of.
     *
     * When JWT-authenticated, resolves from username/user_id params via UserFactory.
     * When session-authenticated, returns the current session user.
     *
     * If the token carries a signed "user"/"user_id" claim, that identity
     * is used and any username/user_id params must match it.
     *
     * NOTE: mw-mcp-server does not sign a user claim yet, so on the JWT path
     * the acting user is currently taken from request params and is only as
     * trustworthy as the MCP server's shared secret.
     *
     * @param string|null $username
     * @param int|null $userId
     * @return UserIdentity
     */
    protected function resolveUser(?string $username = null, ?int $userId = null): UserIdentity
    {
        if ($this->isJwtAuthenticated) {
            $userFactory = MediaWikiServices::getInstance()->getUserFactory();

            $claimed = $this->resolveClaimedUser();
            if ($claimed !== null) {
                $nameMismatch = $username !== null && $username !== ''
                    && $userFactory->newFromName($username)?->getName() !== $claimed->getName();
                $idMismatch = $userId !== null && $userId !== 0 && $userId !== $claimed->getId();
                if ($nameMismatch || $idMismatch) {
                    $this->dieWithError('apierror-mwassistant-user-mismatch', 'user-mismatch', [], 403);
                }
                return $claimed;
            }

            // Prefer username lookup (more reliable for loading from DB)
            if ($username) {
                $user = $userFactory->newFromName($username);
                if ($user) {
                    $user->load();
                    if ($user->getId() > 0) {
                        return $user;
                    }
                }
            }

            // Fallback to ID lookup
            if ($userId) {
                $user = $userFactory->newFromId($userId);
                if ($user) {
                    $user->load();
                    if ($user->getId() > 0) {
                        return $user;
                    }
                }
            }

            // No valid user identity provided or resolved — reject rather
            // than silently falling back to anonymous, which could grant
            // unintended access on wikis with mixed namespace permissions.
            if ($username || $userId) {
                $this->dieWithError(
                    ['apierror-mwassistant-badparams', 'Could not resolve the specified user'],
                    'user-not-found'
                );
            }

            // No user params at all — fall back to anonymous
            return $userFactory->newAnonymous();
        }

        // Session auth -> use current user
        return $this->getUser();
    }

    /**
     * Resolve the acting user from signed JWT claims ("user" / "user_id"),
     * or null when the token carries no user claim.
     *
     * @return UserIdentity|null
     */
    private function resolveClaimedUser(): ?UserIdentity
    {
        $claimName = $this->jwtClaims['user'] ?? null;
        $claimId = $this->jwtClaims['user_id'] ?? null;
        if (($claimName === null || $claimName === '') && $claimId === null) {
            return null;
        }

        $userFactory = MediaWikiServices::getInstance()->getUserFactory();
        if ($claimId !== null && (int)$claimId === 0) {
            return $userFactory->newAnonymous();
        }

        $user = null;
        if (is_string($claimName) && $claimName !== '') {
            $user = $userFactory->newFromName($claimName);
        } elseif (is_int($claimId)) {
            $user = $userFactory->newFromId($claimId);
        }
        if ($user) {
            $user->load();
        }

        $valid = $user && $user->getId() > 0
            && ($claimId === null || (int)$claimId === $user->getId());
        if (!$valid) {
            $this->dieWithError(
                ['apierror-mwassistant-badparams', 'Could not resolve the user in the token'],
                'user-not-found'
            );
        }
        return $user;
    }

    /**
     * Tell the MW API framework that this module handles its own auth.
     *
     * On private wikis ($wgGroupPermissions['*']['read'] = false), the API
     * framework rejects all anonymous requests with 'readapidenied' BEFORE
     * our checkAccess() runs. Since MCP→MW requests use JWT auth (not MW
     * sessions), they appear anonymous to the framework.
     *
     * Returning false here skips that global check. Security is still
     * enforced by checkAccess() (JWT verification or session + mwassistant-use).
     *
     * @return bool
     */
    public function isReadMode(): bool
    {
        return false;
    }

    /**
     * Validate that the MediaWiki session user is permitted to use the extension.
     *
     * @return void
     */
    private function assertSessionAccess(): void
    {
        $user = $this->getUser();

        // Permission is defined in extension.json as an explicit right: 'mwassistant-use'
        if (!$user || !$user->isAllowed('mwassistant-use')) {
            $this->dieWithError(
                ['apierror-permissiondenied', $this->msg('action-mwassistant-use')],
                'permissiondenied'
            );
        }
    }
}
