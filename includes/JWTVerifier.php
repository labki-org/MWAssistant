<?php

namespace MWAssistant;

use RuntimeException;

/**
 * Validates inbound JWTs originating from mw-mcp-server.
 *
 * This implementation:
 *   - Performs safe base64url decoding with full error detection
 *   - Performs strict JSON decoding with error handling
 *   - Validates all claims (iss, aud, iat, exp, scope)
 *   - Applies standard JWT leeway (clock skew tolerance)
 *   - Uses constant-time signature comparison
 *   - Provides hardened logging for debugging
 */
class JWTVerifier
{

    /** @var int Acceptable clock skew in seconds. */
    private int $leeway = 10;

    /**
     * Verify a JWT produced by mw-mcp-server.
     *
     * @param string $token
     * @param array<string> $requiredScopes
     * @return array|false Decoded payload or false when invalid
     */
    public function verifyMCPToMWToken(
        string $token,
        array $requiredScopes = []
    ): array|false {
        try {
            // ------------------------------------------------------------------
            // Step 1: Split
            // ------------------------------------------------------------------
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return $this->fail('Malformed JWT: must contain 3 parts');
            }

            [$headerB64, $payloadB64, $signatureB64] = $parts;

            $headerJson = $this->safeBase64UrlDecode($headerB64, 'header');
            $payloadJson = $this->safeBase64UrlDecode($payloadB64, 'payload');

            // ------------------------------------------------------------------
            // Step 2: Decode JSON safely
            // ------------------------------------------------------------------
            $header = json_decode($headerJson, true);
            $payload = json_decode($payloadJson, true);

            if (!is_array($header) || !is_array($payload)) {
                return $this->fail('Invalid JSON in JWT header or payload');
            }

            // ------------------------------------------------------------------
            // Step 3: Validate header (algorithm, etc.)
            // ------------------------------------------------------------------
            if (($header['alg'] ?? null) !== 'HS256') {
                return $this->fail('Unsupported JWT alg (expected HS256)', $payload);
            }

            // ------------------------------------------------------------------
            // Step 4: Validate signature
            // ------------------------------------------------------------------
            $secret = Config::getJWTMCPToMWSecret();

            $expectedSig = $this->base64UrlEncode(
                hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true)
            );

            if (!hash_equals($expectedSig, $signatureB64)) {
                return $this->fail('JWT signature verification failed', $payload);
            }

            // ------------------------------------------------------------------
            // Step 5: Required claims
            // ------------------------------------------------------------------
            if (!isset($payload['iss']) || !is_string($payload['iss'])) {
                return $this->fail('Missing or invalid "iss" claim', $payload);
            }

            if (!isset($payload['aud']) || !is_string($payload['aud'])) {
                return $this->fail('Missing or invalid "aud" claim', $payload);
            }

            if (!isset($payload['iat']) || !is_int($payload['iat'])) {
                return $this->fail('Missing or invalid "iat" claim', $payload);
            }

            if (!isset($payload['exp']) || !is_int($payload['exp'])) {
                return $this->fail('Missing or invalid "exp" claim', $payload);
            }

            // ------------------------------------------------------------------
            // Step 6: Logical validation of iss/aud
            // ------------------------------------------------------------------
            if ($payload['iss'] !== 'mw-mcp-server') {
                return $this->fail('Invalid issuer', $payload);
            }

            if ($payload['aud'] !== 'MWAssistant') {
                return $this->fail('Invalid audience', $payload);
            }

            // ------------------------------------------------------------------
            // Step 7: Validate timestamps with leeway
            // ------------------------------------------------------------------
            $now = time();

            if ($payload['iat'] > $now + $this->leeway) {
                return $this->fail('Token issued in the future', $payload);
            }

            if ($payload['exp'] < $now - $this->leeway) {
                return $this->fail('Token expired', $payload);
            }

            // ------------------------------------------------------------------
            // Step 8: Validate required scopes
            // ------------------------------------------------------------------
            $tokenScopes = $payload['scope'] ?? [];

            if (!is_array($tokenScopes)) {
                return $this->fail('Invalid "scope" claim', $payload);
            }

            foreach ($requiredScopes as $scope) {
                if (!in_array($scope, $tokenScopes, true)) {
                    return $this->fail("Missing required scope: {$scope}", $payload);
                }
            }

            // All checks passed successfully
            return $payload;

        } catch (\Throwable $e) {
            // Safe fallback
            return $this->fail("Exception during verification: {$e->getMessage()}");
        }
    }

    // ========================================================================
    // Internal helpers
    // ========================================================================

    private function safeBase64UrlDecode(string $value, string $context): string
    {
        $padded = str_pad(
            strtr($value, '-_', '+/'),
            strlen($value) % 4 === 0 ? strlen($value) : strlen($value) + (4 - strlen($value) % 4),
            '=',
            STR_PAD_RIGHT
        );

        $decoded = base64_decode($padded, true);

        if ($decoded === false) {
            throw new RuntimeException("Invalid base64url encoding in JWT {$context}");
        }

        return $decoded;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function fail(string $reason, ?array $claims = null): false
    {
        $this->logFailure($reason, $claims);
        return false;
    }

    private function logFailure(string $reason, ?array $claims = null): void
    {
        $entry = [
            'reason' => $reason,
            'timestamp' => time(),
            'iss' => $claims['iss'] ?? null,
            'aud' => $claims['aud'] ?? null,
            'scopes' => $claims['scope'] ?? null,
        ];

        \wfDebugLog('mwassistant-jwt', json_encode($entry));
    }
}
