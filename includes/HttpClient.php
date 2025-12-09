<?php

namespace MWAssistant;

use MediaWiki\MediaWikiServices;
use MediaWiki\Http\HttpRequestFactory;

class HttpClient
{

    private string $baseUrl;
    private HttpRequestFactory $factory;

    public function __construct()
    {
        $this->baseUrl = rtrim(Config::getMCPBaseUrl(), '/');
        $this->factory = MediaWikiServices::getInstance()->getHttpRequestFactory();
    }

    /**
     * @return array [ 'ok' => bool, 'code' => int, 'body' => mixed|string ]
     */
    public function postJson(string $path, array $payload, string $jwt): array
    {
        $url = $this->baseUrl . $path;

        $req = $this->factory->create(
            $url,
            [
                'method' => 'POST',
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $jwt,
                ],
                'postData' => json_encode($payload)
            ],
            __METHOD__
        );

        $status = $req->execute();
        $code = $req->getStatus();
        $body = $req->getContent();

        if (!$status->isOK()) {
            return ['ok' => false, 'code' => $code, 'body' => $body];
        }

        $decoded = json_decode($body, true);
        return ['ok' => true, 'code' => $code, 'body' => $decoded ?: $body];
    }
}
