<?php

namespace MWAssistant;

use MediaWiki\MediaWikiServices;
use MediaWiki\Http\HttpRequestFactory;
use StatusValue;

/**
 * Minimal wrapper around MediaWiki HttpRequestFactory for
 * communicating with the MCP server using JSON requests.
 *
 * All MCP client classes rely on this for consistent:
 *   - request construction
 *   - authentication header injection
 *   - JSON encoding/decoding
 *   - error normalization
 */
class HttpClient
{

    private string $baseUrl;
    private HttpRequestFactory $factory;

    public function __construct(?string $baseUrl = null)
    {
        // Allow override (EmbeddingsClient passes explicit base).
        $this->baseUrl = rtrim($baseUrl ?? Config::getMCPBaseUrl(), '/');
        $this->factory = MediaWikiServices::getInstance()->getHttpRequestFactory();
    }

    /**
     * POST JSON helper.
     *
     * @param string $path
     * @param array $payload
     * @param string $jwt
     * @return array [ 'ok' => bool, 'code' => int|null, 'body' => mixed|string ]
     */
    public function postJson(string $path, array $payload, string $jwt): array
    {
        return $this->request('POST', $path, $payload, $jwt);
    }

    /**
     * GET JSON helper.
     *
     * @param string $path
     * @param array $query
     * @param string $jwt
     * @return array
     */
    public function getJson(string $path, array $query, string $jwt): array
    {
        $path = $query ? $path . '?' . http_build_query($query) : $path;
        return $this->request('GET', $path, [], $jwt);
    }

    /**
     * DELETE helper.
     *
     * @param string $path
     * @param string $jwt
     * @return array
     */
    public function delete(string $path, string $jwt): array
    {
        return $this->request('DELETE', $path, [], $jwt);
    }

    /**
     * Low-level request function.
     *
     * Returns:
     *   [ 'ok' => true,  'code' => int, 'body' => decoded JSON or raw string ]
     *   [ 'ok' => false, 'code' => int|null, 'body' => error string or error array ]
     *
     * @param string $method
     * @param string $path
     * @param array $payload
     * @param string $jwt
     * @return array
     */
    public function request(string $method, string $path, array $payload, string $jwt): array
    {
        $url = $this->baseUrl . $path;

        $options = [
            'method' => $method,
            'timeout' => 30,
        ];

        if (!empty($payload)) {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
            );

            if ($json === false) {
                return [
                    'ok' => false,
                    'code' => null,
                    'body' => 'JSON encoding error: ' . json_last_error_msg(),
                ];
            }

            $options['postData'] = $json;
        }

        $req = $this->factory->create($url, $options, __METHOD__);

        $req->setHeader('Content-Type', 'application/json');
        $req->setHeader('Authorization', 'Bearer ' . $jwt);
        $req->setHeader('User-Agent', 'MWAssistant/1.0.0 (MediaWiki)');

        // Transport-level execution (network/HTTP)
        $status = $req->execute();

        // Network-level error (connection failed, DNS, timeout, etc.)
        if (!$status instanceof StatusValue || !$status->isOK()) {
            $err = $status instanceof StatusValue
                ? $status->getWikiText()
                : 'Unknown HTTP transport failure';

            \wfDebugLog(
                'mwassistant',
                "HttpClient transport error for {$url}: {$err}"
            );

            return [
                'ok' => false,
                'code' => null,
                'body' => "Transport error: {$err}",
            ];
        }

        $httpCode = $req->getStatus();
        $bodyRaw = $req->getContent();

        // Normalize body to UTF-8 to avoid JSON decode crashes
        $bodyRaw = is_string($bodyRaw)
            ? mb_convert_encoding($bodyRaw, 'UTF-8', 'UTF-8')
            : $bodyRaw;

        // HTTP-level failure (non-2xx)
        if ($httpCode < 200 || $httpCode >= 300) {
            \wfDebugLog(
                'mwassistant',
                "HttpClient HTTP error {$httpCode} for {$url}: {$bodyRaw}"
            );

            return [
                'ok' => false,
                'code' => $httpCode,
                'body' => $bodyRaw,
            ];
        }

        // Attempt JSON decode
        $decoded = json_decode($bodyRaw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            \wfDebugLog(
                'mwassistant',
                "HttpClient JSON decode error for {$url}: " . json_last_error_msg()
            );

            return [
                'ok' => false,
                'code' => $httpCode,
                'body' => 'Invalid JSON response: ' . json_last_error_msg(),
            ];
        }

        return [
            'ok' => true,
            'code' => $httpCode,
            'body' => $decoded,
        ];
    }
}
