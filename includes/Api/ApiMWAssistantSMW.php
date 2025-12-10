<?php

namespace MWAssistant\Api;

use MWAssistant\Api\ApiMWAssistantBase;
use MWAssistant\MCP\SMWClient;

class ApiMWAssistantSMW extends ApiMWAssistantBase
{

    public function execute()
    {
        $this->checkAccess(['smw_query']);

        $user = $this->getUser();


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
