<?php

namespace MWAssistant\Api;

use MediaWiki\Api\ApiBase;
use MWAssistant\MCP\ActionsClient;

class ApiMWAssistantAction extends ApiBase
{

    public function execute()
    {
        $user = $this->getUser();
        if (!$user->isAllowed('mwassistant-use')) {
            $this->dieWithError('apierror-permissiondenied', 'permissiondenied');
        }

        $params = $this->extractRequestParams();
        $title = $params['title'];
        $content = $params['content'];
        $summary = $params['summary'] ?? '';

        $client = new ActionsClient();
        $result = $client->editPage($user, $title, $content, $summary);

        $this->getResult()->addValue(null, $this->getModuleName(), $result);
    }

    public function getAllowedParams()
    {
        return [
            'title' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => true,
            ],
            'content' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => true,
            ],
            'summary' => [
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
