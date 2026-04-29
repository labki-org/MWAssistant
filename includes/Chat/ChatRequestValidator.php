<?php

namespace MWAssistant\Chat;

/**
 * Validates the shape of a chat request payload (messages array, optional
 * session id, context). Both the buffered API endpoint and the streaming
 * Special page accept the same shape, so the validation lives here in one
 * place rather than being duplicated.
 *
 * Each validator returns null on success, or an [errorCode, errorMessage]
 * pair on failure. Callers translate that into whichever error mechanism
 * suits them (ApiBase::dieWithError vs. SpecialPage JSON response).
 */
class ChatRequestValidator
{
    public const ALLOWED_ROLES = ['user', 'assistant', 'system'];
    public const ALLOWED_CONTEXTS = ['chat', 'editor'];

    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * Validate that $messages is a non-empty array of {role, content} entries
     * with allowed roles and string content.
     *
     * @param mixed $messages Raw decoded value
     * @return array{0:string,1:string}|null [errorCode, message] on failure, null on success
     */
    public static function validateMessages($messages, bool $requireNonEmpty = false): ?array
    {
        if (!is_array($messages)) {
            return ['messages', 'Invalid messages parameter'];
        }
        if ($requireNonEmpty && empty($messages)) {
            return ['bad_messages', 'messages must be a non-empty array.'];
        }
        foreach ($messages as $i => $msg) {
            if (!is_array($msg)) {
                return ['bad-message', "Message at index {$i} is not an object"];
            }
            if (!isset($msg['role']) || !in_array($msg['role'], self::ALLOWED_ROLES, true)) {
                return ['bad-message-role', "Message at index {$i} has invalid or missing role"];
            }
            if (!isset($msg['content']) || !is_string($msg['content'])) {
                return ['bad-message-content', "Message at index {$i} has invalid or missing content"];
            }
        }
        return null;
    }

    /**
     * Validate context parameter (optional; null/empty defaults to "chat").
     *
     * @param mixed $context
     * @return array{0:string,1:string}|null
     */
    public static function validateContext($context): ?array
    {
        if (!in_array($context, self::ALLOWED_CONTEXTS, true)) {
            return ['bad-context', 'Invalid context parameter (expected "chat" or "editor")'];
        }
        return null;
    }

    /**
     * Validate optional session_id is a UUID v4.
     *
     * @param mixed $sessionId Null, empty string, or candidate UUID
     * @return array{0:string,1:string}|null
     */
    public static function validateSessionId($sessionId): ?array
    {
        if ($sessionId === null || $sessionId === '') {
            return null;
        }
        if (!is_string($sessionId) || !preg_match(self::UUID_V4, $sessionId)) {
            return ['bad-session-id', 'Invalid session_id format (expected UUID v4)'];
        }
        return null;
    }
}
