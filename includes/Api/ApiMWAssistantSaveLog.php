<?php

namespace MWAssistant\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\Revision\SlotRecord;

class ApiMWAssistantSaveLog extends ApiBase
{

    public function execute()
    {
        $user = $this->getUser();

        if (!$user->isAllowed('mwassistant-use')) {
            $this->dieWithError('apierror-permissiondenied', 'permissiondenied');
        }

        if ($user->isAnon()) {
            $this->dieWithError('apierror-mustbeloggedin', 'mustbeloggedin');
        }

        $params = $this->extractRequestParams();
        $sessionId = $params['session_id'];
        $content = $params['content'];

        // Construct title: User:<Username>/ChatLogs/<Date>_<SessionID>
        // Use a safe date format
        $date = date('Y-m-d');
        // Sanitize session ID just in case, though Title::makeTitleSafe handles significantly
        $safeSessionId = preg_replace('/[^a-zA-Z0-9-]/', '', $sessionId);

        $pageTitleStr = "User:" . $user->getName() . "/ChatLogs/" . $date . "_" . $safeSessionId;
        $title = Title::newFromText($pageTitleStr);

        if (!$title) {
            $this->dieWithError('apierror-invalidtitle', 'invalidtitle');
        }

        try {
            $services = MediaWikiServices::getInstance();
            $wikiPageFactory = $services->getWikiPageFactory();
            $wikiPage = $wikiPageFactory->newFromTitle($title);

            $updater = $wikiPage->newPageUpdater($user);

            // We overwrite the content for this session log
            $contentObj = $services->getContentHandlerFactory()
                ->getContentHandler('wikitext')
                ->makeContent($content);

            $updater->setContent(SlotRecord::MAIN, $contentObj);

            // Check if page exists to determine summary
            $isNew = !$wikiPage->exists();
            $summary = $isNew ? "Creating chat log for session $safeSessionId" : "Updating chat log for session $safeSessionId";

            $updater->saveRevision(
                \MediaWiki\CommentFormatter\CommentParser::newUnformatted($summary),
                EDIT_INTERNAL // Minor flag or internal flag
            );

            $status = $updater->getStatus();

            if (!$status->isOK()) {
                $this->dieWithError($status->getMessage()->getKey());
            }

            $result = [
                'success' => true,
                'title' => $title->getPrefixedText(),
                'url' => $title->getFullURL()
            ];

            $this->getResult()->addValue(null, $this->getModuleName(), $result);

        } catch (\Exception $e) {
            $this->dieWithException($e);
        }
    }

    public function getAllowedParams()
    {
        return [
            'session_id' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => true,
            ],
            'content' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => true,
            ]
        ];
    }

    public function needsToken()
    {
        return 'csrf';
    }
}
