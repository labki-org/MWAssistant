<?php

namespace MWAssistant\Api;

use MediaWiki\Api\ApiBase;
use MWAssistant\MCP\ChatClient;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\CommentFormatter\CommentParser;

class ApiMWAssistantChat extends ApiBase
{

    public function execute()
    {
        $user = $this->getUser();
        if (!$user->isAllowed('mwassistant-use')) {
            $this->dieWithError('apierror-permissiondenied', 'permissiondenied');
        }

        $params = $this->extractRequestParams();
        $messages = json_decode($params['messages'], true);
        $sessionId = $params['session_id'];

        if (!is_array($messages)) {
            $this->dieWithError('apierror-badparams', 'messages');
        }

        $client = new ChatClient();
        $response = $client->chat($user, $messages, $sessionId);

        // Auto-log the interaction if successful
        if ($response && isset($response['messages'])) {
            $lastMsg = end($response['messages']);
            if (isset($lastMsg['content'])) {
                $logInfo = $this->logInteraction($user, $sessionId, $messages, $lastMsg['content']);
                if ($logInfo) {
                    $response['log_info'] = $logInfo;
                }
            }
        }

        $this->getResult()->addValue(null, $this->getModuleName(), $response);
    }

    private function logInteraction($user, $sessionId, $inputMessages, $assistantResponse)
    {
        // Get the last user message
        $lastUserMsg = '';
        if (is_array($inputMessages)) {
            $lastItem = end($inputMessages);
            if (isset($lastItem['content'])) {
                $lastUserMsg = $lastItem['content'];
            }
        }

        if (!$lastUserMsg) {
            return null;
        }

        $date = date('Y-m-d');
        $safeSessionId = preg_replace('/[^a-zA-Z0-9-]/', '', $sessionId);
        $pageTitleStr = "User:" . $user->getName() . "/ChatLogs/" . $date . "_" . $safeSessionId;
        $title = Title::newFromText($pageTitleStr);

        if (!$title) {
            return null;
        }

        try {
            $services = MediaWikiServices::getInstance();
            $wikiPage = $services->getWikiPageFactory()->newFromTitle($title);
            $updater = $wikiPage->newPageUpdater($user);

            // Append Content
            $newText = "\n* '''User:''' " . $lastUserMsg . "\n* '''Assistant:''' " . $assistantResponse . "\n";

            // If page doesn't exist, add header
            if (!$wikiPage->exists()) {
                $header = "== Chat Session: " . date('Y-m-d H:i:s') . " ==\n'''Session ID:''' " . $safeSessionId . "\n----\n";
                $newText = $header . $newText;
            } else {
                // Load existing content to append
                $revision = $wikiPage->getRevisionRecord();
                if ($revision) {
                    $content = $revision->getContent(SlotRecord::MAIN);
                    if ($content instanceof \WikitextContent) {
                        $newText = $content->getText() . $newText;
                    }
                }
            }

            $contentObj = $services->getContentHandlerFactory()
                ->getContentHandler('wikitext')
                ->makeContent($newText, $title);

            $updater->setContent(SlotRecord::MAIN, $contentObj);

            $summary = $wikiPage->exists() ? "Logging chat message" : "Creating chat log";

            // Passing string directly avoids compatibility issues with CommentStoreComment factory methods
            $updater->saveRevision(
                \MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment($summary),
                CONSTANT('EDIT_INTERNAL') | CONSTANT('EDIT_SUPPRESS_RC')
            );

            return [
                'title' => $title->getPrefixedText(),
                'url' => $title->getFullURL()
            ];

        } catch (\Throwable $e) {
            // Silently fail logging so we don't break the chat response
            wfDebugLog('mwassistant', 'Failed to log chat: ' . $e->getMessage());
            return null;
        }
    }

    public function getAllowedParams()
    {
        return [
            'messages' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => true,
            ],
            'session_id' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => false,
            ]
        ];
    }

    public function needsToken()
    {
        return 'csrf';
    }
}
