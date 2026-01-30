<?php

namespace MWAssistant\Tests\Integration;

use MediaWikiIntegrationTestCase;
use MediaWiki\User\UserIdentity;
use MWAssistant\Config;
use MWAssistant\JWT;

/**
 * @covers \MWAssistant\JWT
 * @group MWAssistant
 */
class JWTTest extends MediaWikiIntegrationTestCase
{
    private const TEST_SECRET = 'test-mw-to-mcp-secret';
    private const TEST_TTL = 60;
    private const TEST_WIKI_ID = 'test-wiki';
    private const TEST_API_URL = 'https://wiki.example.com/api.php';
    private const TEST_MCP_BASE_URL = 'http://localhost:8000';

    protected function setUp(): void
    {
        parent::setUp();

        $ref = new \ReflectionProperty(Config::class, 'mainConfig');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $this->overrideConfigValue('MWAssistantJWTMWToMCPSecret', self::TEST_SECRET);
        $this->overrideConfigValue('MWAssistantJWTTTL', self::TEST_TTL);
        $this->overrideConfigValue('MWAssistantWikiId', self::TEST_WIKI_ID);
        $this->overrideConfigValue('MWAssistantWikiApiUrl', self::TEST_API_URL);
        $this->overrideConfigValue('MWAssistantMCPBaseUrl', self::TEST_MCP_BASE_URL);
    }

    private function createMockUser(): UserIdentity
    {
        $user = $this->createMock(UserIdentity::class);
        $user->method('getName')->willReturn('TestUser');
        $user->method('getId')->willReturn(42);
        return $user;
    }

    private static function base64UrlDecode(string $data): string
    {
        $padded = str_pad(
            strtr($data, '-_', '+/'),
            strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4),
            '=',
            STR_PAD_RIGHT
        );
        return base64_decode($padded, true);
    }

    private function decodeTokenParts(string $token): array
    {
        $parts = explode('.', $token);
        return [
            json_decode(self::base64UrlDecode($parts[0]), true),
            json_decode(self::base64UrlDecode($parts[1]), true),
            $parts[2],
        ];
    }

    // =================================================================
    // Tests
    // =================================================================

    public function testCreateTokenReturnsThreePartJWT(): void
    {
        $token = JWT::createMWToMCPToken($this->createMockUser());
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }

    public function testTokenHasCorrectHeader(): void
    {
        $token = JWT::createMWToMCPToken($this->createMockUser());
        [$header] = $this->decodeTokenParts($token);

        $this->assertSame('HS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);
    }

    public function testPayloadContainsAllRequiredClaims(): void
    {
        $token = JWT::createMWToMCPToken($this->createMockUser(), ['sysop'], ['chat_completion']);
        [, $payload] = $this->decodeTokenParts($token);

        $required = ['iss', 'aud', 'iat', 'exp', 'user', 'user_id', 'wiki_id', 'roles', 'scope', 'allowed_namespaces', 'api_url'];
        foreach ($required as $claim) {
            $this->assertArrayHasKey($claim, $payload, "Missing claim: {$claim}");
        }
    }

    public function testTokenSetsCorrectIssuerAndAudience(): void
    {
        $token = JWT::createMWToMCPToken($this->createMockUser());
        [, $payload] = $this->decodeTokenParts($token);

        $this->assertSame('MWAssistant', $payload['iss']);
        $this->assertSame('mw-mcp-server', $payload['aud']);
    }

    public function testTokenSetsCorrectExpiration(): void
    {
        $token = JWT::createMWToMCPToken($this->createMockUser());
        [, $payload] = $this->decodeTokenParts($token);

        $this->assertSame($payload['iat'] + self::TEST_TTL, $payload['exp']);
    }

    public function testTokenIncludesRolesAndScopes(): void
    {
        $roles = ['sysop', 'bureaucrat'];
        $scopes = ['chat_completion', 'search'];
        $token = JWT::createMWToMCPToken($this->createMockUser(), $roles, $scopes);
        [, $payload] = $this->decodeTokenParts($token);

        $this->assertSame($roles, $payload['roles']);
        $this->assertSame($scopes, $payload['scope']);
    }

    public function testTokenIncludesWikiId(): void
    {
        $token = JWT::createMWToMCPToken($this->createMockUser());
        [, $payload] = $this->decodeTokenParts($token);

        $this->assertSame(self::TEST_WIKI_ID, $payload['wiki_id']);
    }

    public function testTokenSignatureIsValid(): void
    {
        $token = JWT::createMWToMCPToken($this->createMockUser());
        $parts = explode('.', $token);

        $expectedSig = rtrim(strtr(
            base64_encode(
                hash_hmac('sha256', "{$parts[0]}.{$parts[1]}", self::TEST_SECRET, true)
            ),
            '+/', '-_'
        ), '=');

        $this->assertSame($expectedSig, $parts[2]);
    }
}
