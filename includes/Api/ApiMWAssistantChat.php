<?php

namespace MWAssistant\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\Revision\SlotRecord;
use MWAssistant\Api\ApiMWAssistantBase;
use MWAssistant\MCP\ChatClient;
use WikitextContent;
use MediaWiki\CommentStore\CommentStoreComment;

/**
 * API endpoint for submitting chat messages to the MCP server
 * and returning model responses.
 *
 * Responsibilities:
 *  - Authenticate (JWT or session) using inherited checkAccess().
 *  - Validate & parse the incoming message payload.
 *  - Forward conversation state to ChatClient.
 *  - Optionally log the most recent interaction to a per-user chat log page.
 */
class ApiMWAssistantChat extends ApiMWAssistantBase
{

    /**
     * Main API execution.
     *
     * @return void
     */
    public function execute(): void
    {
        // Require the JWT scope "chat_completion" if authenticated via JWT.
        $this->checkAccess(['chat_completion']);

        $params = $this->extractRequestParams();

        // -------------------------------------------------------------
        // Parameter validation
        // -------------------------------------------------------------
        $messages = json_decode($params['messages'], true);
        $sessionId = $params['session_id'] ?? null;
        $context = $params['context'] ?? 'chat';

        if (!is_array($messages)) {
            $this->dieWithError(
                ['apierror-badparams', 'Invalid messages parameter'],
                'messages'
            );
        }

        // -------------------------------------------------------------
        // Invoke the MCP chat backend
        // -------------------------------------------------------------
        $user = $this->getUser();
        $client = new ChatClient();
        $response = $client->chat($user, $messages, $sessionId, $context);

        // -------------------------------------------------------------
        // Optional logging of successful assistant reply
        // -------------------------------------------------------------
        if (is_array($response) && isset($response['messages'])) {
            $lastMsg = end($response['messages']);
            $assistantReply = $lastMsg['content'] ?? null;

            if ($assistantReply) {
                $logInfo = $this->logInteraction(
                    $user,
                    $sessionId,
                    $messages,
                    $assistantReply,
                    $response['used_tools'] ?? []
                );

                if ($logInfo) {
                    $response['log_info'] = $logInfo;
                }
            }
        }

        // -------------------------------------------------------------
        // Output result
        // -------------------------------------------------------------
        $this->getResult()->addValue(
            null,
            $this->getModuleName(),
            $response
        );
    }

    /**
     * Append a chat exchange to the user's log page.
     *
     * Structure:
     *   User:<username>/ChatLogs/YYYY-MM-DD_<sessionId>
     *
     * Failures are intentionally silent so they never block chat responses.
     *
     * @param \UserIdentity $user
     * @param string|null $sessionId
     * @param array $inputMessages
     * @param string $assistantResponse
     *
     * @return array|null ['title' => string, 'url' => string] or null on failure
     */
    private function logInteraction(
        $user,
        ?string $sessionId,
        array $inputMessages,
        string $assistantResponse,
        array $usedTools = []
    ): ?array {

        // -------------------------------------------------------------
        // Extract last user message
        // -------------------------------------------------------------
        $lastUserMsg = '';
        if ($inputMessages) {
            $last = end($inputMessages);
            $lastUserMsg = $last['content'] ?? '';
        }

        if (!$lastUserMsg) {
            return null;
        }

        // -------------------------------------------------------------
        // Build safe log page title
        // -------------------------------------------------------------
        $date = date('Y-m-d');
        $safeSessionId = $sessionId ?
            preg_replace('/[^a-zA-Z0-9\-]/', '', $sessionId) :
            'default';

        $pageTitleStr = sprintf(
            'User:%s/ChatLogs/%s_%s',
            $user->getName(),
            $date,
            $safeSessionId
        );

        $title = Title::newFromText($pageTitleStr);
        if (!$title instanceof Title) {
            return null;
        }

        // -------------------------------------------------------------
        // Update or create the page
        // -------------------------------------------------------------
        try {
            $services = MediaWikiServices::getInstance();
            $wikiPage = $services->getWikiPageFactory()->newFromTitle($title);
            $updater = $wikiPage->newPageUpdater($user);

            // Escape Category and Property links to prevent categorization/assignment
            // e.g. [[Category:Foo]] -> [[:Category:Foo]]
            $safeAssistantResponse = preg_replace('/\[\[\s*(Category|Property)\s*:/i', '[[:$1:', $assistantResponse);
            $safeAssistantResponse = $this->convertMarkdownToWikitext($safeAssistantResponse);

            // Format tool usage if present
            $toolLog = '';
            if (!empty($usedTools)) {
                $toolRows = [];
                foreach ($usedTools as $tool) {
                    $name = htmlspecialchars($tool['name'] ?? 'unknown');
                    $args = htmlspecialchars(json_encode($tool['args'] ?? []));
                    $toolRows[] = "* '''$name''': <code style=\"font-size:0.9em\">$args</code>";
                }
                $toolContent = implode("\n", $toolRows);

                $toolLog = "\n{| class=\"mw-collapsible mw-collapsed wikitable\" style=\"width:100%; margin-top:0.5em;\"\n"
                    . "! Tool Usage\n"
                    . "|-\n"
                    . "| \n{$toolContent}\n"
                    . "|}\n";
            }

            // Build new entry
            $entry = "\n'''User:'''\n{$lastUserMsg}\n\n"
                . "'''Assistant:'''\n{$safeAssistantResponse}\n"
                . $toolLog . "\n\n----\n";

            $contentText = '';

            if (!$wikiPage->exists()) {
                // Add header for new log page
                $header = sprintf(
                    "== Chat Session: %s ==\n'''Session ID:''' %s\n----\n",
                    date('Y-m-d H:i:s'),
                    $safeSessionId
                );
                $contentText = $header . $entry;
            } else {
                // Append to existing page
                $revision = $wikiPage->getRevisionRecord();
                $existingContent = '';

                if ($revision) {
                    $contentObj = $revision->getContent(SlotRecord::MAIN);
                    if ($contentObj instanceof WikitextContent) {
                        $existingContent = $contentObj->getText();
                    }
                }

                $contentText = $existingContent . $entry;
            }

            $contentObj = $services
                ->getContentHandlerFactory()
                ->getContentHandler('wikitext')
                ->makeContent($contentText, $title);

            $updater->setContent(SlotRecord::MAIN, $contentObj);

            $summary = $wikiPage->exists()
                ? 'Logging chat message'
                : 'Creating chat log';

            // Save the revision with suppressed RecentChanges entry
            $updater->saveRevision(
                CommentStoreComment::newUnsavedComment($summary),
                EDIT_INTERNAL | EDIT_SUPPRESS_RC
            );

            return [
                'title' => $title->getPrefixedText(),
                'url' => $title->getFullURL()
            ];

        } catch (\Throwable $e) {
            wfDebugLog(
                'mwassistant',
                'Failed to log chat: ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function getAllowedParams(): array
    {
        return [
            'messages' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => true,
            ],
            'session_id' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => false,
            ],
            'context' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => false, // Default handled in execute()
            ],
        ];
    }

    /**
     * Chat actions require a CSRF token when invoked from the UI.
     *
     * @return string
     */
    public function needsToken(): string
    {
        return 'csrf';
    }

    /**
     * Convert common Markdown syntax to Wikitext.
     *
     * @param string $text
     * @return string
     */
    private function convertMarkdownToWikitext(string $text): string
    {
        // Bold: **text** -> '''text'''
        $text = str_replace('**', "'''", $text);

        // Lists: - item -> * item
        $text = preg_replace('/^-\s/m', '* ', $text);

        // Headers: ### Header -> === Header ===
        $text = preg_replace('/^###\s+(.*?)$/m', '=== $1 ===', $text);
        $text = preg_replace('/^##\s+(.*?)$/m', '== $1 ==', $text);

        return $text;
    }
}
