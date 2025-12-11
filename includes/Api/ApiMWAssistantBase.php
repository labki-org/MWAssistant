<?php

namespace MWAssistant\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
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
                'apierror-permissiondenied',
                'permissiondenied'
            );
        }
    }
}
