<?php

namespace MWAssistant\Api;

use InvalidArgumentException;
use MediaWiki\Api\ApiResult;
use MediaWiki\Html\Html;
use MWAssistant\SMW\SMWParserEvaluator;

/**
 * API endpoint for executing Semantic MediaWiki queries.
 *
 * Responsibilities:
 *  - Authenticate via JWT or session (checkAccess()).
 *  - Validate "query" parameter.
 *  - Execute the SMW #ask query via SMWParserEvaluator, which parses it
 *    with SMW's QueryProcessor (never the wikitext parser) and drops
 *    results the acting user cannot read. SMW does not respect
 *    Lockdown/ControlAccess read restrictions on its own.
 *  - Return structured rows plus a fully escaped HTML table rendering.
 */
class ApiMWAssistantSMW extends ApiMWAssistantBase
{

    /**
     * Execute the SMW query request.
     *
     * @return void
     */
    public function execute(): void
    {
        // Require JWT scope "smw_query" when authenticated via JWT.
        $this->checkAccess(['smw_query']);

        $params = $this->extractRequestParams();
        $query = $params['query'] ?? '';
        $username = $params['username'] ?? null;
        $userId = $params['user_id'] ?? null;

        // -------------------------------------------------------------
        // Validate parameter
        // -------------------------------------------------------------
        if (!is_string($query) || trim($query) === '') {
            $this->dieWithError(
                ['apierror-mwassistant-badparams', 'SMW query cannot be empty.'],
                'query'
            );
        }

        if (!class_exists(\SMW\Query\QueryProcessor::class)) {
            $this->dieWithError('apierror-mwassistant-smw-unavailable', 'smw-unavailable');
        }

        $user = $this->resolveUser($username, $userId);

        // -------------------------------------------------------------
        // Execute query (results are already permission-filtered)
        // -------------------------------------------------------------
        $evaluator = new SMWParserEvaluator();
        try {
            $data = $evaluator->evaluate($user, $query);
        } catch (InvalidArgumentException $e) {
            $this->dieWithError(
                ['apierror-mwassistant-badparams', wfEscapeWikiText($e->getMessage())],
                'query'
            );
        }

        // -------------------------------------------------------------
        // Output result
        // -------------------------------------------------------------
        $rows = $data['rows'];
        foreach ($rows as &$row) {
            // Keep printouts a JSON object even when empty.
            ApiResult::setArrayType($row['printouts'], 'assoc');
        }
        unset($row);

        $this->getResult()->addValue(
            null,
            $this->getModuleName(),
            [
                'result' => $this->renderHtml($data['printouts'], $rows),
                'rows' => $rows,
                'printouts' => $data['printouts'],
                'count' => $data['count'],
                'has_more' => $data['has_more'],
                'errors' => $data['errors'],
            ]
        );
    }

    /**
     * Render the filtered rows as an HTML table. Every value is escaped
     * (Html::element), so the output is safe to insert into the DOM.
     *
     * @param string[] $labels Printout labels (column headers)
     * @param array $rows Rows as returned by SMWParserEvaluator::evaluate()
     * @return string
     */
    private function renderHtml(array $labels, array $rows): string
    {
        if (!$rows) {
            return Html::element('p', [], $this->msg('mwassistant-smw-noresults')->text());
        }

        $header = Html::element('th', [], $this->msg('mwassistant-smw-col-page')->text());
        foreach ($labels as $label) {
            $header .= Html::element('th', [], $label);
        }
        $html = Html::rawElement('tr', [], $header);

        foreach ($rows as $row) {
            $cells = Html::element('td', [], $row['title']);
            foreach ($labels as $label) {
                $values = array_map(
                    static fn ($value) => htmlspecialchars($value),
                    $row['printouts'][$label] ?? []
                );
                $cells .= Html::rawElement('td', [], implode('<br>', $values));
            }
            $html .= Html::rawElement('tr', [], $cells);
        }

        return Html::rawElement('table', ['class' => 'wikitable mwassistant-smw-result'], $html);
    }

    /**
     * @inheritDoc
     */
    public function getAllowedParams(): array
    {
        return [
            'query' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => true,
            ],
            'username' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => false,
            ],
            'user_id' => [
                self::PARAM_TYPE => 'integer',
                self::PARAM_REQUIRED => false,
            ],
        ];
    }

    /**
     * SMW queries from MCP are authenticated via JWT, so we do not
     * require a CSRF token (which is for browser sessions).
     *
     * @return bool
     */
    public function needsToken(): bool
    {
        return false;
    }
}
