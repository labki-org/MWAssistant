<?php

namespace MWAssistant;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Http\HttpRequestFactory;

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
    /** @var int Default HTTP request timeout in seconds */
    private const REQUEST_TIMEOUT_SECONDS = 30;

    private string $baseUrl;
    private HttpRequestFactory $factory;
    private \Psr\Log\LoggerInterface $logger;

    public function __construct(?string $baseUrl = null)
    {
        // Allow override (EmbeddingsClient passes explicit base).
        $this->baseUrl = rtrim($baseUrl ?? Config::getMCPBaseUrl(), '/');
        $this->factory = MediaWikiServices::getInstance()->getHttpRequestFactory();
        $this->logger = LoggerFactory::getInstance(Config::LOGGER_CHANNEL);
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
     * Uses a Guzzle client from HttpRequestFactory rather than
     * MWHttpRequest: MW's GuzzleHttpRequest only attaches a body for POST,
     * which silently dropped the JSON payload of e.g. DELETE /embeddings/page.
     *
     * Returns:
     *   [ 'ok' => true,  'code' => int, 'body' => decoded JSON array, 'request_id' => string ]
     *   [ 'ok' => false, 'code' => int|null, 'body' => error detail, 'request_id' => string ]
     *
     * The error 'body' is diagnostic detail for logs only; handleResponse()
     * never forwards it to API clients.
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

        // Generate a unique request ID for distributed tracing
        $requestId = bin2hex(random_bytes(8));

        $options = [
            'http_errors' => false,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $jwt,
                'User-Agent' => 'MWAssistant/1.0.0 (MediaWiki)',
                'X-Request-ID' => $requestId,
            ],
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
                    'request_id' => $requestId,
                ];
            }

            $options['body'] = $json;
            // Some servers reject 'Expect: 100-continue' with a 417.
            $options['expect'] = false;
        }

        try {
            $response = $this->guzzleClient()->request($method, $url, $options);
        } catch (GuzzleException $e) {
            // No HTTP response at all (connection refused, timeout, DNS...).
            return [
                'ok' => false,
                'code' => null,
                'body' => 'Transport error: ' . $e->getMessage(),
                'request_id' => $requestId,
            ];
        }

        $httpCode = $response->getStatusCode();
        $bodyRaw = (string)$response->getBody();

        // Normalize body to UTF-8 to avoid JSON decode crashes
        $bodyRaw = mb_convert_encoding($bodyRaw, 'UTF-8', 'UTF-8');

        // HTTP-level failure (non-2xx)
        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'ok' => false,
                'code' => $httpCode,
                'body' => $bodyRaw,
                'request_id' => $requestId,
            ];
        }

        // 204 No Content (or any empty 2xx) is a success with nothing to decode.
        if (trim($bodyRaw) === '') {
            return [
                'ok' => true,
                'code' => $httpCode,
                'body' => [],
                'request_id' => $requestId,
            ];
        }

        $decoded = json_decode($bodyRaw, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [
                'ok' => false,
                'code' => $httpCode,
                'body' => 'Invalid JSON response: ' . (json_last_error() !== JSON_ERROR_NONE
                    ? json_last_error_msg()
                    : 'expected an object or array, got ' . get_debug_type($decoded)),
                'request_id' => $requestId,
            ];
        }

        return [
            'ok' => true,
            'code' => $httpCode,
            'body' => $decoded,
            'request_id' => $requestId,
        ];
    }

    /**
     * Guzzle client with MW's timeout normalization and $wgHTTPProxy.
     */
    private function guzzleClient(): Client
    {
        $config = [
            'timeout' => self::REQUEST_TIMEOUT_SECONDS,
            // Match MWHttpRequest's default, and never replay the bearer
            // token to wherever a redirect points.
            'allow_redirects' => false,
        ];

        $proxy = MediaWikiServices::getInstance()->getMainConfig()->get(MainConfigNames::HTTPProxy);
        if (is_string($proxy) && $proxy !== '') {
            $config['proxy'] = $proxy;
        }

        return $this->factory->createGuzzleClient($config);
    }

    /**
     * Normalize MCP HTTP response into a stable array format.
     *
     * On success: returns $resp['body'] (always an array)
     * On error: returns ['error' => true, 'status' => int|null, 'message' => string, 'request_id' => string]
     *
     * Upstream bodies and transport errors can carry internal hostnames,
     * stack traces or validation dumps, so they're logged (with the
     * X-Request-ID for correlation with MCP server logs) and replaced by a
     * generic message. The HTTP status is preserved so callers can still
     * classify the failure (e.g. jobs retrying 5xx but not 4xx).
     *
     * @param array $resp Raw response from request()
     * @param string $context Context label for error messages
     * @return array
     */
    public function handleResponse(array $resp, string $context = 'request'): array
    {
        $ok = $resp['ok'] ?? false;
        $code = $resp['code'] ?? null;
        $requestId = $resp['request_id'] ?? null;

        if (!$ok) {
            $body = $resp['body'] ?? null;
            $detail = is_string($body) ? $body : json_encode($body);

            $this->logger->error(
                'MCP {context} failed (status {status}, request {requestId}): {detail}',
                [
                    'context' => $context,
                    'status' => $code ?? 'none',
                    'requestId' => $requestId ?? 'none',
                    'detail' => $detail ?: 'Unknown error',
                ]
            );

            $message = $code === null
                ? "MCP {$context} failed: could not reach the assistant server"
                : "MCP {$context} failed (HTTP {$code})";

            return [
                'error' => true,
                'status' => $code,
                'message' => $message,
                'request_id' => $requestId,
            ];
        }

        $body = $resp['body'] ?? [];
        return is_array($body) ? $body : [];
    }

    /**
     * Fetch user groups/roles for JWT construction.
     *
     * @param \MediaWiki\User\UserIdentity $user
     * @return string[]
     */
    public static function getUserRoles(\MediaWiki\User\UserIdentity $user): array
    {
        return MediaWikiServices::getInstance()
            ->getUserGroupManager()
            ->getUserGroups($user);
    }
}
