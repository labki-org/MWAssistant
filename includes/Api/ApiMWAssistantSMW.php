<?php

namespace MWAssistant\Api;

use MediaWiki\Api\ApiBase;
use MWAssistant\MCP\SMWClient;

class ApiMWAssistantSMW extends ApiBase
{

    public function execute()
    {
        $user = $this->getUser();
        if (!$user->isAllowed('mwassistant-use')) {
            $this->dieWithError('apierror-permissiondenied', 'permissiondenied');
        }

        $params = $this->extractRequestParams();
        $query = $params['query'];

        $client = new SMWClient();
        $result = $client->query($user, $query);

        $this->getResult()->addValue(null, $this->getModuleName(), $result);
    }

    public function getAllowedParams()
    {
        return [
            'query' => [
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
