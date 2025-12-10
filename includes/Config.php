<?php

namespace MWAssistant;

use MediaWiki\MediaWikiServices;

class Config
{

    public static function getMCPBaseUrl(): string
    {
        return MediaWikiServices::getInstance()
            ->getMainConfig()
            ->get('MWAssistantMCPBaseUrl');
    }

    public static function getJWTMWToMCPSecret(): string
    {
        $secret = MediaWikiServices::getInstance()
            ->getMainConfig()
            ->get('MWAssistantJWTMWToMCPSecret');

        if (empty($secret)) {
            throw new \RuntimeException(
                'MWAssistant: MWAssistantJWTMWToMCPSecret must be configured in LocalSettings.php'
            );
        }

        return $secret;
    }

    public static function getJWTMCPToMWSecret(): string
    {
        $secret = MediaWikiServices::getInstance()
            ->getMainConfig()
            ->get('MWAssistantJWTMCPToMWSecret');

        if (empty($secret)) {
            throw new \RuntimeException(
                'MWAssistant: MWAssistantJWTMCPToMWSecret must be configured in LocalSettings.php'
            );
        }

        return $secret;
    }

    public static function isEnabled(): bool
    {
        return (bool) MediaWikiServices::getInstance()
            ->getMainConfig()
            ->get('MWAssistantEnabled');
    }

    public static function getJWTTTL(): int
    {
        return (int) MediaWikiServices::getInstance()
            ->getMainConfig()
            ->get('MWAssistantJWTTTL');
    }

    public static function isAutoEmbedEnabled(): bool
    {
        return (bool) MediaWikiServices::getInstance()
            ->getMainConfig()
            ->get('MWAssistantAutoEmbed');
    }
}
