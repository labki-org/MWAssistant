<?php

namespace MWAssistant\Tests\Integration;

use MediaWikiIntegrationTestCase;
use MWAssistant\Config;
use MWAssistant\JWTVerifier;

/**
 * @covers \MWAssistant\JWTVerifier
 * @group MWAssistant
 */
class JWTVerifierTest extends MediaWikiIntegrationTestCase
{
    private const TEST_SECRET = 'test-mcp-to-mw-secret-key';

    private JWTVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $ref = new \ReflectionProperty(Config::class, 'mainConfig');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $this->overrideConfigValue('MWAssistantJWTMCPToMWSecret', self::TEST_SECRET);

        $this->verifier = new JWTVerifier();
    }

    // =================================================================
    // Helpers
    // =================================================================

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Build a JWT with controllable header, payload, and signing secret.
     */
    private function buildToken(
        array $headerOverrides = [],
        array $payloadOverrides = [],
        ?string $secretOverride = null
    ): string {
        $header = array_merge([
            'typ' => 'JWT',
            'alg' => 'HS256',
        ], $headerOverrides);

        $now = time();
        $payload = array_merge([
            'iss' => 'mw-mcp-server',
            'aud' => 'MWAssistant',
            'iat' => $now,
            'exp' => $now + 300,
            'scope' => ['chat_completion'],
        ], $payloadOverrides);

        $headerB64 = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadB64 = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));

        $secret = $secretOverride ?? self::TEST_SECRET;
        $sig = self::base64UrlEncode(
            hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true)
        );

        return "{$headerB64}.{$payloadB64}.{$sig}";
    }

    // =================================================================
    // Valid tokens
    // =================================================================

    public function testVerifyValidTokenReturnsPayload(): void
    {
        $token = $this->buildToken();
        $result = $this->verifier->verifyMCPToMWToken($token);

        $this->assertIsArray($result);
        $this->assertSame('mw-mcp-server', $result['iss']);
        $this->assertSame('MWAssistant', $result['aud']);
    }

    public function testVerifyWithRequiredScopesPasses(): void
    {
        $token = $this->buildToken([], ['scope' => ['chat_completion']]);
        $result = $this->verifier->verifyMCPToMWToken($token, ['chat_completion']);

        $this->assertIsArray($result);
    }

    public function testVerifyWithMultipleRequiredScopes(): void
    {
        $token = $this->buildToken([], ['scope' => ['chat_completion', 'search', 'embed']]);
        $result = $this->verifier->verifyMCPToMWToken($token, ['chat_completion', 'search']);

        $this->assertIsArray($result);
    }

    // =================================================================
    // Malformed tokens
    // =================================================================

    public function testReturnsFalseForEmptyString(): void
    {
        $this->assertFalse($this->verifier->verifyMCPToMWToken(''));
    }

    public function testReturnsFalseForTwoParts(): void
    {
        $this->assertFalse($this->verifier->verifyMCPToMWToken('a.b'));
    }

    public function testReturnsFalseForFourParts(): void
    {
        $this->assertFalse($this->verifier->verifyMCPToMWToken('a.b.c.d'));
    }

    public function testReturnsFalseForInvalidBase64(): void
    {
        $this->assertFalse($this->verifier->verifyMCPToMWToken('!!!.@@@.###'));
    }

    // =================================================================
    // Algorithm
    // =================================================================

    public function testReturnsFalseForWrongAlgorithm(): void
    {
        $token = $this->buildToken(['alg' => 'RS256']);
        $this->assertFalse($this->verifier->verifyMCPToMWToken($token));
    }

    public function testReturnsFalseForNoneAlgorithm(): void
    {
        $token = $this->buildToken(['alg' => 'none']);
        $this->assertFalse($this->verifier->verifyMCPToMWToken($token));
    }

    // =================================================================
    // Signature
    // =================================================================

    public function testReturnsFalseForTamperedSignature(): void
    {
        $token = $this->buildToken();
        // Flip the last character of the signature
        $parts = explode('.', $token);
        $parts[2] = $parts[2] . 'X';
        $tampered = implode('.', $parts);

        $this->assertFalse($this->verifier->verifyMCPToMWToken($tampered));
    }

    public function testReturnsFalseForWrongSecret(): void
    {
        $token = $this->buildToken([], [], 'wrong-secret');
        $this->assertFalse($this->verifier->verifyMCPToMWToken($token));
    }

    // =================================================================
    // Claims
    // =================================================================

    public function testReturnsFalseForWrongIssuer(): void
    {
        $token = $this->buildToken([], ['iss' => 'evil-server']);
        $this->assertFalse($this->verifier->verifyMCPToMWToken($token));
    }

    public function testReturnsFalseForWrongAudience(): void
    {
        $token = $this->buildToken([], ['aud' => 'wrong-audience']);
        $this->assertFalse($this->verifier->verifyMCPToMWToken($token));
    }

    /**
     * @dataProvider missingClaimsProvider
     */
    public function testReturnsFalseForMissingRequiredClaims(string $claim): void
    {
        $now = time();
        $payload = [
            'iss' => 'mw-mcp-server',
            'aud' => 'MWAssistant',
            'iat' => $now,
            'exp' => $now + 300,
            'scope' => ['chat_completion'],
        ];
        unset($payload[$claim]);

        $token = $this->buildToken([], $payload);
        $this->assertFalse($this->verifier->verifyMCPToMWToken($token));
    }

    public static function missingClaimsProvider(): array
    {
        return [
            'missing iss' => ['iss'],
            'missing aud' => ['aud'],
            'missing iat' => ['iat'],
            'missing exp' => ['exp'],
        ];
    }

    // =================================================================
    // Timestamps
    // =================================================================

    public function testReturnsFalseForExpiredToken(): void
    {
        $token = $this->buildToken([], ['exp' => time() - 300]);
        $this->assertFalse($this->verifier->verifyMCPToMWToken($token));
    }

    public function testReturnsFalseForFutureIssuedToken(): void
    {
        $token = $this->buildToken([], ['iat' => time() + 300]);
        $this->assertFalse($this->verifier->verifyMCPToMWToken($token));
    }

    public function testAcceptsTokenWithinLeeway(): void
    {
        // Token expired 5 seconds ago — within the 10s leeway
        $token = $this->buildToken([], ['exp' => time() - 5]);
        $result = $this->verifier->verifyMCPToMWToken($token);

        $this->assertIsArray($result);
    }

    // =================================================================
    // Scopes
    // =================================================================

    public function testReturnsFalseForMissingRequiredScope(): void
    {
        $token = $this->buildToken([], ['scope' => ['search']]);
        $this->assertFalse(
            $this->verifier->verifyMCPToMWToken($token, ['chat_completion'])
        );
    }

    public function testPassesWhenNoScopesRequired(): void
    {
        $token = $this->buildToken([], ['scope' => []]);
        $result = $this->verifier->verifyMCPToMWToken($token, []);

        $this->assertIsArray($result);
    }
}
