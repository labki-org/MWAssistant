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
        return $this->request('POST', $path, $payload, $jwt);
    }

    public function getJson(string $path, array $query, string $jwt): array
    {
        $path .= $query ? ('?' . http_build_query($query)) : '';
        return $this->request('GET', $path, [], $jwt);
    }

    public function request(string $method, string $path, array $payload, string $jwt): array
    {
        $url = $this->baseUrl . $path;

        $options = [
            'method' => $method,
            'timeout' => 30
        ];

        if (!empty($payload)) {
            $options['postData'] = json_encode($payload);
        }

        $req = $this->factory->create($url, $options, __METHOD__);

        $req->setHeader('Content-Type', 'application/json');
        $req->setHeader('Authorization', 'Bearer ' . $jwt);
        $req->setHeader('User-Agent', 'MWAssistant/0.1.0 (MediaWiki)');

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
