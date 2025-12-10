<?php

namespace MWAssistant\Api;

use MWAssistant\Api\ApiMWAssistantBase;
use MWAssistant\MCP\SearchClient;

class ApiMWAssistantSearch extends ApiMWAssistantBase
{

    public function execute()
    {
        $this->checkAccess(['search']);

        $user = $this->getUser();

        $params = $this->extractRequestParams();
        $query = $params['query'];

        $client = new SearchClient();
        $result = $client->search($user, $query);

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
