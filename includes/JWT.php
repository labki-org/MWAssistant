<?php

namespace MWAssistant;

use MediaWiki\User\UserIdentity;

class JWT
{

    public static function createForUser(UserIdentity $user, array $roles = []): string
    {
        $secret = Config::getJWTSecret();
        $now = time();

        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'sub' => $user->getName(),
            'roles' => $roles,
            'client_id' => 'mw_extension',
            'iat' => $now,
            'exp' => $now + 3600 // 1 hour expiration
        ]);

        \wfDebugLog('mwassistant', 'JWT Data: secret len=' . strlen($secret) . ', algo=HS256, payload=' . $payload);
        \wfDebugLog('mwassistant', 'JWT Data: secret=' . $secret);
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
