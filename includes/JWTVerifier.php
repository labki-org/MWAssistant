<?php

namespace MWAssistant;

class JWTVerifier
{

    /**
     * Verify a JWT from mw-mcp-server
     * 
     * @param string $token The JWT token to verify
     * @param array $requiredScopes Scopes that must be present in the token
     * @return array|false Decoded payload on success, false on failure
     */
    public function verifyMCPToMWToken(string $token, array $requiredScopes = []): array|false
    {
        try {
            // Split token into parts
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                $this->logVerificationFailure('Invalid token format: not 3 parts');
                return false;
            }

            [$headerB64, $payloadB64, $signatureB64] = $parts;

            // Decode header and payload
            $header = json_decode($this->base64UrlDecode($headerB64), true);
            $payload = json_decode($this->base64UrlDecode($payloadB64), true);

            if (!$header || !$payload) {
                $this->logVerificationFailure('Invalid JSON in header or payload');
                return false;
            }

            // Verify algorithm
            if (!isset($header['alg']) || $header['alg'] !== 'HS256') {
                $this->logVerificationFailure('Invalid algorithm', $payload);
                return false;
            }

            // Verify signature
            $secret = Config::getJWTMCPToMWSecret();
            $expectedSignature = $this->base64UrlEncode(
                hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true)
            );

            if (!hash_equals($expectedSignature, $signatureB64)) {
                $this->logVerificationFailure('Signature verification failed', $payload);
                return false;
            }

            // Verify issuer
            if (!isset($payload['iss']) || $payload['iss'] !== 'mw-mcp-server') {
                $this->logVerificationFailure('Invalid issuer', $payload);
                return false;
            }

            // Verify audience
            if (!isset($payload['aud']) || $payload['aud'] !== 'MWAssistant') {
                $this->logVerificationFailure('Invalid audience', $payload);
                return false;
            }

            // Verify expiration
            $now = time();
            if (!isset($payload['exp']) || $payload['exp'] < $now) {
                $this->logVerificationFailure('Token expired', $payload);
                return false;
            }

            // Verify issued at (not in future)
            if (!isset($payload['iat']) || $payload['iat'] > $now + 5) { // Allow 5 second clock skew
                $this->logVerificationFailure('Invalid issued-at time', $payload);
                return false;
            }

            // Verify scopes
            if (!empty($requiredScopes)) {
                $tokenScopes = $payload['scope'] ?? [];
                foreach ($requiredScopes as $scope) {
                    if (!in_array($scope, $tokenScopes, true)) {
                        $this->logVerificationFailure('Missing required scope: ' . $scope, $payload);
                        return false;
                    }
                }
            }

            // All checks passed
            return $payload;

        } catch (\Throwable $e) {
            $this->logVerificationFailure('Exception during verification: ' . $e->getMessage());
            return false;
        }
    }

    private function base64UrlDecode(string $data): string
    {
        $padded = str_pad($data, ceil(strlen($data) / 4) * 4, '=', STR_PAD_RIGHT);
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $padded));
    }

    private function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private function logVerificationFailure(string $reason, ?array $claims = null): void
    {
        $logData = [
            'reason' => $reason,
            'timestamp' => time(),
            'iss' => $claims['iss'] ?? 'unknown',
            'aud' => $claims['aud'] ?? 'unknown',
            'scope' => $claims['scope'] ?? []
        ];

        \wfDebugLog('mwassistant-jwt', json_encode($logData));
    }
}
