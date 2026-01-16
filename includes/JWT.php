<?php

namespace MWAssistant;

use MediaWiki\User\UserIdentity;
use RuntimeException;

/**
 * JWT utility for MW → MCP authentication.
 *
 * Produces HS256-signed short-lived tokens that the MCP server validates.
 * Does NOT decode or validate inbound tokens (handled by JWTVerifier).
 */
class JWT
{

    /**
     * Create a short-lived MW→MCP JWT.
     *
     * @param UserIdentity $user   The MediaWiki user
     * @param array<string> $roles User groups (e.g., ["sysop", "bureaucrat"])
     * @param array<string> $scopes Operation scopes (e.g., ["chat_completion"])
     *
     * @return string Signed HS256 JWT
     *
     * @throws RuntimeException if encoding or signing fails
     */
    public static function createMWToMCPToken(
        UserIdentity $user,
        array $roles = [],
        array $scopes = []
    ): string {
        $secret = Config::getJWTMWToMCPSecret();
        $ttl = Config::getJWTTTL();
        $wikiId = Config::getWikiId();

        $now = time();
        $exp = $now + $ttl;

        // Normalize username to UTF-8
        $username = mb_convert_encoding($user->getName(), 'UTF-8', 'UTF-8');

        // Build header + payload ------------------------------------------------
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
        ];

        $payload = [
            'iss' => 'MWAssistant',
            'aud' => 'mw-mcp-server',
            'iat' => $now,
            'exp' => $exp,
            'jti' => bin2hex(random_bytes(8)),  // Optional but recommended
            'user' => $username,
            'user_id' => $user->getId(),
            'wiki_id' => $wikiId,
            'roles' => array_values($roles),
            'scope' => array_values($scopes),
            'allowed_namespaces' => NamespacePermissions::getReadableNamespaces($user),
        ];

        $encodedHeader = self::safeJsonBase64($header, 'JWT header');
        $encodedPayload = self::safeJsonBase64($payload, 'JWT payload');

        // Sign ------------------------------------------------------------------
        $signature = hash_hmac(
            'sha256',
            "{$encodedHeader}.{$encodedPayload}",
            $secret,
            true
        );

        if ($signature === false) {
            throw new RuntimeException("Failed to sign JWT.");
        }

        $encodedSignature = self::base64UrlEncode($signature);

        return "{$encodedHeader}.{$encodedPayload}.{$encodedSignature}";
    }

    /**
     * Safely encode an array → JSON → base64url with full validation.
     *
     * @param array $data
     * @param string $context Description for error messages
     * @return string
     */
    private static function safeJsonBase64(array $data, string $context): string
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            throw new RuntimeException("Failed to JSON encode {$context}: " . json_last_error_msg());
        }

        return self::base64UrlEncode($json);
    }

    /**
     * URL-safe Base64 encode (as specified by RFC 7515).
     *
     * @param string $data
     * @return string
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(
            strtr(base64_encode($data), '+/', '-_'),
            '='
        );
    }
}
