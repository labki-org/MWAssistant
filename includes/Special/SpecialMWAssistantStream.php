<?php

namespace MWAssistant\Special;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\SpecialPage\UnlistedSpecialPage;
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
    private const ALLOWED_ROLES = ['user', 'assistant', 'system'];
    private const ALLOWED_CONTEXTS = ['chat', 'editor'];
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

        if (!is_array($messages) || empty($messages)) {
            $this->writeErrorAndExit(400, 'bad_messages', 'messages must be a non-empty array.');
            return;
        }
        if (!in_array($context, self::ALLOWED_CONTEXTS, true)) {
            $this->writeErrorAndExit(400, 'bad_context', 'context must be "chat" or "editor".');
            return;
        }
        if ($sessionId !== null && $sessionId !== '') {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sessionId)) {
                $this->writeErrorAndExit(400, 'bad_session_id', 'session_id must be a UUID v4.');
                return;
            }
        }
        foreach ($messages as $i => $msg) {
            if (!is_array($msg)
                || !isset($msg['role'])
                || !in_array($msg['role'], self::ALLOWED_ROLES, true)
                || !isset($msg['content'])
                || !is_string($msg['content'])
            ) {
                $this->writeErrorAndExit(400, 'bad_message', "Message at index {$i} is malformed.");
                return;
            }
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
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_implicit_flush(true);

        $url = Config::getMCPBaseUrl() . '/chat/stream';
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('Failed to encode upstream payload: ' . json_last_error_msg());
        }

        $ch = curl_init();
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
            CURLOPT_BUFFERSIZE => 256,
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
        curl_close($ch);

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
}
