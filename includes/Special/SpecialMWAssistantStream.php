<?php

namespace MWAssistant\Special;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\SpecialPage\UnlistedSpecialPage;
use MWAssistant\Chat\ChatRequestValidator;
use MWAssistant\Config;
use MWAssistant\HttpClient;
use MWAssistant\JWT;
use Throwable;

/**
 * Streaming proxy for the MCP server's POST /chat/stream endpoint.
 *
 * MediaWiki's API framework buffers responses through ApiResult and is
 * unsuitable for Server-Sent Events. This special page bypasses that
 * pipeline: it disables the standard output, mints a short-lived MW->MCP
 * JWT, opens a streaming cURL connection to /chat/stream, and forwards
 * raw event bytes to the browser as they arrive.
 *
 * The JS chat UI hits this endpoint via fetch() + ReadableStream so the
 * Authorization header (held server-side here) doesn't need to be visible
 * to the browser, and so each tool_start / tool_result / assistant_message
 * SSE frame can be rendered as it is produced.
 */
class SpecialMWAssistantStream extends UnlistedSpecialPage
{
    private const STREAM_TIMEOUT_SECONDS = 300;

    public function __construct()
    {
        parent::__construct('MWAssistantStream', 'mwassistant-use');
    }

    /**
     * @param string|null $par
     */
    public function execute($par)
    {
        $request = $this->getRequest();
        $user = $this->getUser();
        $logger = LoggerFactory::getInstance(Config::LOGGER_CHANNEL);

        $out = $this->getOutput();
        $out->disable();

        if (!$user->isAllowed('mwassistant-use')) {
            $this->writeErrorAndExit(403, 'permission_denied', 'You do not have permission to use MWAssistant.');
            return;
        }

        if (!$request->wasPosted()) {
            $this->writeErrorAndExit(405, 'method_not_allowed', 'POST required.');
            return;
        }

        $token = $request->getVal('token', '');
        if (!$user->matchEditToken($token)) {
            $this->writeErrorAndExit(403, 'bad_token', 'Invalid CSRF token.');
            return;
        }

        $rawBody = file_get_contents('php://input');
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $this->writeErrorAndExit(400, 'bad_json', 'Request body must be JSON.');
            return;
        }

        $messages = $payload['messages'] ?? null;
        $sessionId = $payload['session_id'] ?? null;
        $context = $payload['context'] ?? 'chat';

        $err = ChatRequestValidator::validateMessages($messages, true);
        if ($err === null) {
            $err = ChatRequestValidator::validateContext($context);
        }
        if ($err === null) {
            $err = ChatRequestValidator::validateSessionId($sessionId);
        }
        if ($err !== null) {
            $this->writeErrorAndExit(400, $err[0], $err[1]);
            return;
        }

        $upstream = [
            'messages' => $messages,
            'context' => $context,
        ];
        if ($sessionId !== null && $sessionId !== '') {
            $upstream['session_id'] = $sessionId;
        }

        $roles = HttpClient::getUserRoles($user);
        try {
            $jwt = JWT::createMWToMCPToken($user, $roles, ['chat_completion']);
        } catch (Throwable $e) {
            $logger->error('Failed to mint JWT for streaming chat: {err}', ['err' => $e->getMessage()]);
            $this->writeErrorAndExit(500, 'jwt_error', 'Could not create authentication token.');
            return;
        }

        try {
            $this->streamSse($upstream, $jwt);
        } catch (Throwable $e) {
            $logger->error('Streaming chat proxy failed: {err}', ['err' => $e->getMessage()]);
            echo "event: error\ndata: " . json_encode([
                'code' => 'proxy_failure',
                'message' => 'Streaming connection failed.',
            ]) . "\n\n";
            @flush();
        }
    }

    /**
     * Open cURL stream to MCP /chat/stream and forward each chunk to the browser.
     *
     * @param array<string,mixed> $payload Validated upstream request body
     * @param string $jwt MW-to-MCP token
     */
    private function streamSse(array $payload, string $jwt): void
    {
        $this->disableAllBuffering();

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        header('Content-Encoding: identity');
        header_remove('Content-Length');

        // Emit a 4 KB SSE comment immediately. SSE comments (lines beginning
        // with ":") are ignored by clients but force any FastCGI / mod_proxy
        // buffer to flush, since the default Apache FastCGI buffer is ~4 KB.
        echo ':' . str_repeat(' ', 4096) . "\n\n";
        @flush();

        echo "event: heartbeat\ndata: {\"ts\":" . time() . "}\n\n";
        @flush();

        $url = Config::getMCPBaseUrl() . '/chat/stream';
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('Failed to encode upstream payload: ' . json_last_error_msg());
        }

        $ch = curl_init();
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $jwt,
                    'Content-Type: application/json',
                    'Accept: text/event-stream',
                    'User-Agent: MWAssistant/1.0.0 (MediaWiki streaming proxy)',
                    'X-Request-ID: ' . bin2hex(random_bytes(8)),
                ],
                CURLOPT_TIMEOUT => self::STREAM_TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER => false,
                CURLOPT_TCP_NODELAY => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) {
                    if (connection_aborted()) {
                        return -1;
                    }
                    echo $chunk;
                    @flush();
                    return strlen($chunk);
                },
            ]);

            $ok = curl_exec($ch);
            $errno = curl_errno($ch);
            $errstr = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($ch);
        }

        if (!$ok && !connection_aborted()) {
            $logger = LoggerFactory::getInstance(Config::LOGGER_CHANNEL);
            $logger->warning('MCP /chat/stream cURL failed: errno={errno} err={err} http={http}', [
                'errno' => $errno,
                'err' => $errstr,
                'http' => $httpCode,
            ]);
            echo "event: error\ndata: " . json_encode([
                'code' => 'upstream_failure',
                'message' => "Upstream MCP request failed (HTTP {$httpCode}).",
            ]) . "\n\n";
            @flush();
        }
    }

    /**
     * Send a JSON error response and stop. Only safe before SSE headers are sent.
     */
    private function writeErrorAndExit(int $httpCode, string $code, string $message): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode(['error' => $code, 'message' => $message]);
    }

    /**
     * Defense-in-depth: disable every layer of output buffering / compression
     * we know about so each echo + flush() actually reaches the client.
     *
     * Stacks we have to defeat, from innermost to outermost:
     *   1. PHP user-level ob_start() buffers (incl. MW's own).
     *   2. PHP zlib.output_compression (gzip in the engine).
     *   3. PHP output_handler / output_buffering ini directives.
     *   4. Apache mod_deflate (when DEFLATE filter is active).
     *   5. mod_proxy_fcgi default 8 KB write buffer (php-fpm setups).
     *
     * Layers 4 + 5 also need server-side config (mod_deflate exclusion,
     * `flushpackets=on` on ProxyPass) — see deploy notes — but the
     * `apache_setenv('no-gzip')` hint plus the 4 KB SSE warmup comment
     * cover the common defaults.
     */
    private function disableAllBuffering(): void
    {
        if (function_exists('wfResetOutputBuffers')) {
            wfResetOutputBuffers();
        } else {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
        }

        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        @ob_implicit_flush(true);

        // Apache mod_deflate / mod_gzip respect these per-request hints.
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
            @apache_setenv('dont-vary', '1');
        }
    }

}
