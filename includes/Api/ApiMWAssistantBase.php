<?php

namespace MWAssistant\Api;

use MediaWiki\Api\ApiBase;
use MWAssistant\JWTVerifier;

abstract class ApiMWAssistantBase extends ApiBase
{
    protected bool $isJwtAuthenticated = false;

    /**
     * Verify access for the API module.
     * Supports both JWT (Message from MCP) and Session (User Browser) authentication.
     * 
     * @param array $requiredScopes Scopes required if authenticating via JWT
     */
    protected function checkAccess(array $requiredScopes)
    {
        $authHeader = $this->getRequest()->getHeader('Authorization');

        // 1. JWT Authentication Path
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            $verifier = new JWTVerifier();
            $payload = $verifier->verifyMCPToMWToken($token, $requiredScopes);

            if (!$payload) {
                $this->dieWithError(['apierror-mwassistant-invalid-jwt', 'wikitext'], 'invalid_jwt', [], 403);
            }

            $this->isJwtAuthenticated = true;
            return;
        }

        // 2. Session Authentication Path
        $user = $this->getUser();
        if (!$user->isAllowed('mwassistant-use')) {
            $this->dieWithError('apierror-permissiondenied', 'permissiondenied');
        }
    }
}
