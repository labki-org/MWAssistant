<?php

namespace MWAssistant\Tests\Integration;

use MediaWikiIntegrationTestCase;
use MWAssistant\Config;
use RuntimeException;

/**
 * @covers \MWAssistant\Config
 * @group MWAssistant
 */
class ConfigTest extends MediaWikiIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset the static config cache so overrideConfigValue() takes effect.
        $ref = new \ReflectionProperty(Config::class, 'mainConfig');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    // -----------------------------------------------------------------
    // getMCPBaseUrl
    // -----------------------------------------------------------------

    public function testGetMCPBaseUrlReturnsConfiguredUrl(): void
    {
        $this->overrideConfigValue('MWAssistantMCPBaseUrl', 'http://localhost:8000');
        $this->assertSame('http://localhost:8000', Config::getMCPBaseUrl());
    }

    public function testGetMCPBaseUrlStripsTrailingSlash(): void
    {
        $this->overrideConfigValue('MWAssistantMCPBaseUrl', 'http://localhost:8000/');
        $this->assertSame('http://localhost:8000', Config::getMCPBaseUrl());
    }

    public function testGetMCPBaseUrlThrowsWhenEmpty(): void
    {
        $this->overrideConfigValue('MWAssistantMCPBaseUrl', '');
        $this->expectException(RuntimeException::class);
        Config::getMCPBaseUrl();
    }

    public function testGetMCPBaseUrlThrowsWhenWhitespaceOnly(): void
    {
        $this->overrideConfigValue('MWAssistantMCPBaseUrl', '   ');
        $this->expectException(RuntimeException::class);
        Config::getMCPBaseUrl();
    }

    // -----------------------------------------------------------------
    // getJWTMWToMCPSecret
    // -----------------------------------------------------------------

    public function testGetJWTMWToMCPSecretReturnsValue(): void
    {
        $this->overrideConfigValue('MWAssistantJWTMWToMCPSecret', 'secret');
        $this->assertSame('secret', Config::getJWTMWToMCPSecret());
    }

    public function testGetJWTMWToMCPSecretThrowsWhenEmpty(): void
    {
        $this->overrideConfigValue('MWAssistantJWTMWToMCPSecret', '');
        $this->expectException(RuntimeException::class);
        Config::getJWTMWToMCPSecret();
    }

    // -----------------------------------------------------------------
    // getJWTMCPToMWSecret
    // -----------------------------------------------------------------

    public function testGetJWTMCPToMWSecretReturnsValue(): void
    {
        $this->overrideConfigValue('MWAssistantJWTMCPToMWSecret', 'secret');
        $this->assertSame('secret', Config::getJWTMCPToMWSecret());
    }

    public function testGetJWTMCPToMWSecretThrowsWhenEmpty(): void
    {
        $this->overrideConfigValue('MWAssistantJWTMCPToMWSecret', '');
        $this->expectException(RuntimeException::class);
        Config::getJWTMCPToMWSecret();
    }

    // -----------------------------------------------------------------
    // isEnabled
    // -----------------------------------------------------------------

    public function testIsEnabledReturnsTrue(): void
    {
        $this->overrideConfigValue('MWAssistantEnabled', true);
        $this->assertTrue(Config::isEnabled());
    }

    public function testIsEnabledReturnsFalse(): void
    {
        $this->overrideConfigValue('MWAssistantEnabled', false);
        $this->assertFalse(Config::isEnabled());
    }

    // -----------------------------------------------------------------
    // isAutoEmbedEnabled
    // -----------------------------------------------------------------

    public function testIsAutoEmbedEnabledReturnsTrue(): void
    {
        $this->overrideConfigValue('MWAssistantAutoEmbed', true);
        $this->assertTrue(Config::isAutoEmbedEnabled());
    }

    public function testIsAutoEmbedEnabledReturnsFalse(): void
    {
        $this->overrideConfigValue('MWAssistantAutoEmbed', false);
        $this->assertFalse(Config::isAutoEmbedEnabled());
    }

    // -----------------------------------------------------------------
    // getJWTTTL
    // -----------------------------------------------------------------

    public function testGetJWTTTLReturnsValue(): void
    {
        $this->overrideConfigValue('MWAssistantJWTTTL', 60);
        $this->assertSame(60, Config::getJWTTTL());
    }

    public function testGetJWTTTLThrowsWhenZero(): void
    {
        $this->overrideConfigValue('MWAssistantJWTTTL', 0);
        $this->expectException(RuntimeException::class);
        Config::getJWTTTL();
    }

    public function testGetJWTTTLThrowsWhenNegative(): void
    {
        $this->overrideConfigValue('MWAssistantJWTTTL', -10);
        $this->expectException(RuntimeException::class);
        Config::getJWTTTL();
    }

    // -----------------------------------------------------------------
    // getWikiId
    // -----------------------------------------------------------------

    public function testGetWikiIdReturnsValue(): void
    {
        $this->overrideConfigValue('MWAssistantWikiId', 'my-wiki');
        $this->assertSame('my-wiki', Config::getWikiId());
    }

    public function testGetWikiIdThrowsWhenEmpty(): void
    {
        $this->overrideConfigValue('MWAssistantWikiId', '');
        $this->expectException(RuntimeException::class);
        Config::getWikiId();
    }

    // -----------------------------------------------------------------
    // getWikiApiUrl
    // -----------------------------------------------------------------

    public function testGetWikiApiUrlReturnsConfiguredValue(): void
    {
        $this->overrideConfigValue('MWAssistantWikiApiUrl', 'https://wiki.example.com/api.php');
        $this->assertSame('https://wiki.example.com/api.php', Config::getWikiApiUrl());
    }

    public function testGetWikiApiUrlFallsBackToServerAndScriptPath(): void
    {
        $this->overrideConfigValue('MWAssistantWikiApiUrl', '');
        $this->overrideConfigValue('Server', 'https://wiki.example.com');
        $this->overrideConfigValue('ScriptPath', '/w');
        $this->assertSame('https://wiki.example.com/w/api.php', Config::getWikiApiUrl());
    }
}
