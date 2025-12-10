<?php

namespace MWAssistant;

use MediaWiki\User\UserIdentity;

class JWT
{

    /**
     * Create a short-lived JWT for MW → MCP authentication
     * 
     * @param UserIdentity $user The MediaWiki user making the request
     * @param array $roles MediaWiki user groups
     * @param array $scopes Request-specific scopes (e.g., ['chat_completion'])
     * @return string JWT token
     */
    public static function createMWToMCPToken(
        UserIdentity $user,
        array $roles = [],
        array $scopes = []
    ): string {
        $secret = Config::getJWTMWToMCPSecret();
        $now = time();

        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'iss' => 'MWAssistant',
            'aud' => 'mw-mcp-server',
            'iat' => $now,
            'exp' => $now + Config::getJWTTTL(),
            'user' => $user->getName(),
            'roles' => $roles,
            'scope' => $scopes
        ]);

        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode($payload);

        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}
