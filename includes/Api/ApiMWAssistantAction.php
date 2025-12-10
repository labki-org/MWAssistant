<?php

namespace MWAssistant\Api;

use MWAssistant\MCP\ActionsClient;


class ApiMWAssistantAction extends ApiMWAssistantBase
{

    public function execute()
    {
        // Step 1: Verify incoming JWT from MCP and MediaWiki user permission
        $this->checkAccess(['mw_action']);

        $user = $this->getUser();


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
