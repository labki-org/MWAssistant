<?php

namespace MWAssistant\Api;

use MediaWiki\Api\ApiBase;
use MWAssistant\MCP\ChatClient;

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
        $result = $client->chat($user, $messages, $sessionId);

        $this->getResult()->addValue(null, $this->getModuleName(), $result);
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
