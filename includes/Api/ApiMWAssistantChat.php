<?php

namespace MWAssistant\Api;

use ApiBase;
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

        if (!is_array($messages)) {
            $this->dieWithError('apierror-badparams', 'messages');
        }

        $client = new ChatClient();
        $result = $client->chat($user, $messages);

        $this->getResult()->addValue(null, $this->getModuleName(), $result);
    }

    public function getAllowedParams()
    {
        return [
            'messages' => [
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
