<?php

namespace MWAssistant\Api;

use MWAssistant\MCP\ActionsClient;


class ApiMWAssistantAction extends ApiMWAssistantBase
{

    public function execute()
    {
        // This API endpoint is currently disabled/deprecated to ensure the LLM
        // cannot directly edit pages.
        $this->dieWithError('apierror-mwassistant-action-disabled', 'actiondisabled');
    }

    public function getAllowedParams()
    {
        return [];
    }

    public function needsToken()
    {
        return 'csrf';
    }
}
