<?php

namespace MWAssistant\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;

/**
 * API endpoint that saves a chat log to a MediaWiki page.
 *
 * Path:
 *   User:<Username>/ChatLogs/<YYYY-MM-DD>_<sessionId>
 *
 * Notes:
 *  - Overwrites the entire log for a given session.
 *  - Intended for frontend-driven chat log editing or replacements.
 *  - Refuses all access for anonymous or unauthorized users.
 */
class ApiMWAssistantSaveLog extends ApiBase
{

    /**
     * Execute the save-log request.
     *
     * @return void
     */
    public function execute(): void
    {
        $user = $this->getUser();

        // -------------------------------------------------------------
        // Permission checks
        // -------------------------------------------------------------
        if (!$user->isAllowed('mwassistant-use')) {
            $this->dieWithError('apierror-permissiondenied', 'permissiondenied');
        }

        if ($user->isAnon()) {
            $this->dieWithError('apierror-mustbeloggedin', 'mustbeloggedin');
        }

        // -------------------------------------------------------------
        // Parameter extraction & validation
        // -------------------------------------------------------------
        $params = $this->extractRequestParams();

        $sessionId = $params['session_id'] ?? '';
        $content = $params['content'] ?? '';

        if (trim($sessionId) === '') {
            $this->dieWithError(
                ['apierror-badparams', 'Session ID cannot be empty.'],
                'session_id'
            );
        }

        if (!is_string($content)) {
            $this->dieWithError(
                ['apierror-badparams', 'Content must be a string.'],
                'content'
            );
        }

        // Escape Category and Property links to prevent categorization/assignment
        // e.g. [[Category:Foo]] -> [[:Category:Foo]]
        $content = preg_replace('/\[\[\s*(Category|Property):/i', '[[:$1:', $content);

        // -------------------------------------------------------------
        // Build safe page title
        // -------------------------------------------------------------
        $date = date('Y-m-d');

        // Only allow safe characters for the session ID
        $safeSessionId = preg_replace('/[^a-zA-Z0-9\-]/', '', $sessionId);
        if ($safeSessionId === '') {
            $safeSessionId = 'default';
        }

        $pageTitleStr = sprintf(
            'User:%s/ChatLogs/%s_%s',
            $user->getName(),
            $date,
            $safeSessionId
        );

        $title = Title::newFromText($pageTitleStr);
        if (!$title) {
            $this->dieWithError('apierror-invalidtitle', 'invalidtitle');
        }

        // -------------------------------------------------------------
        // Write content to the page
        // -------------------------------------------------------------
        try {
            $services = MediaWikiServices::getInstance();

            $wikiPage = $services->getWikiPageFactory()->newFromTitle($title);
            $updater = $wikiPage->newPageUpdater($user);

            // Build new wikitext content object
            $handler = $services->getContentHandlerFactory()->getContentHandler('wikitext');
            $contentObj = $handler->makeContent($content, $title);

            $updater->setContent(SlotRecord::MAIN, $contentObj);

            // Determine edit summary
            $isNew = !$wikiPage->exists();
            $summary = $isNew
                ? "Creating chat log for session $safeSessionId"
                : "Updating chat log for session $safeSessionId";

            // Save revision with correct MW comment object
            $updater->saveRevision(
                CommentStoreComment::newUnsavedComment($summary),
                EDIT_INTERNAL | EDIT_SUPPRESS_RC
            );

            $status = $updater->getStatus();
            if (!$status->isOK()) {
                // Surface underlying MW error message
                $msg = $status->getMessage() ? $status->getMessage()->getKey() : 'apierror-unknown';
                $this->dieWithError($msg);
            }

            // ---------------------------------------------------------
            // Success output
            // ---------------------------------------------------------
            $result = [
                'success' => true,
                'title' => $title->getPrefixedText(),
                'url' => $title->getFullURL(),
            ];

            $this->getResult()->addValue(null, $this->getModuleName(), $result);

        } catch (\Throwable $e) {
            // Let MW handle structured exception formatting
            $this->dieWithException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function getAllowedParams(): array
    {
        return [
            'session_id' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => true,
            ],
            'content' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => true,
            ],
        ];
    }

    /**
     * Requires CSRF token for all UI-originated requests.
     *
     * @return string
     */
    public function needsToken(): string
    {
        return 'csrf';
    }
}
