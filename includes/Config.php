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

    public static function getJWTSecret(): string
    {
        return MediaWikiServices::getInstance()
            ->getMainConfig()
            ->get('MWAssistantJWTSecret');
    }

    public static function isEnabled(): bool
    {
        return (bool) MediaWikiServices::getInstance()
            ->getMainConfig()
            ->get('MWAssistantEnabled');
    }
}
