<?php

namespace MWAssistant\SMW;

use InvalidArgumentException;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\UserIdentity;
use SMW\DataItems\Container;
use SMW\DataItems\DataItem;
use SMW\DataItems\WikiPage;
use SMW\Query\PrintRequest;
use SMW\Query\QueryProcessor;
use SMW\Query\Result\ResultArray;
use SMW\StoreFactory;

/**
 * Service to evaluate SMW #ask queries and return permission-filtered,
 * structured results.
 *
 * Security notes:
 *  - The query is NOT run through the wikitext parser. It is parsed by
 *    SMW's own QueryProcessor and executed directly against the store, so
 *    wikitext such as "}}{{:Secret:Page}}" or {{msgnw:}} is never expanded.
 *  - SMW does not respect Lockdown/ControlAccess read restrictions, so
 *    every result subject, and every page-type printout value, is checked
 *    with PermissionManager::quickUserCan('read') (consistent with the
 *    other MWAssistant API modules). Unreadable subjects are dropped as a
 *    whole row; unreadable values are dropped from their row.
 *  - Result printers are never invoked; the format is forced server side
 *    and results are returned as plain strings.
 *
 * (The class name is historical; it no longer uses the parser.)
 */
class SMWParserEvaluator
{
    /** @var int Default limit for SMW query results */
    public const DEFAULT_LIMIT = 100;

    /** @var int Hard upper bound for SMW query results */
    public const MAX_LIMIT = 500;

    /**
     * Parameters callers may not control. "format" is forced (we never
     * render through a result printer, and format=count would count
     * unreadable pages); "source" could select a non-default query source.
     */
    private const FORCED_PARAMS = ['format', 'source'];

    private PermissionManager $permissionManager;

    public function __construct(?PermissionManager $permissionManager = null)
    {
        $this->permissionManager = $permissionManager
            ?? MediaWikiServices::getInstance()->getPermissionManager();
    }

    /**
     * Evaluate an SMW query string (the inner arguments of #ask, e.g.
     * "[[Category:X]]|?Prop|limit=10") on behalf of $user.
     *
     * @param UserIdentity $user The acting user (used for read checks)
     * @param string $queryArgs The inner arguments for #ask
     * @return array{
     *     printouts: string[],
     *     rows: array<int, array{title: string, printouts: array<string, string[]>}>,
     *     count: int,
     *     has_more: bool,
     *     errors: string[]
     * }
     * @throws InvalidArgumentException If the query uses unsupported features
     */
    public function evaluate(UserIdentity $user, string $queryArgs): array
    {
        // Split exactly like SMW's action=ask API does ("||" is a
        // disjunction inside conditions, "|+" attaches a printout option).
        $rawParams = preg_split('/(?<=[^\|])\|(?=[^\|])(?=[^\+])/', trim($queryArgs));

        [$queryString, $params, $printouts] = QueryProcessor::getComponentsFromFunctionParams(
            $rawParams,
            false
        );

        foreach ($printouts as $printRequest) {
            // Property chains (?A.B) read values through intermediate pages
            // we cannot see or permission-check, so they are not supported.
            if ($printRequest->isMode(PrintRequest::PRINT_CHAIN)) {
                throw new InvalidArgumentException(
                    'Property chain printouts (?A.B) are not supported'
                );
            }
        }

        foreach (array_keys($params) as $name) {
            if (in_array(strtolower($name), self::FORCED_PARAMS, true)) {
                unset($params[$name]);
            }
        }
        $params['format'] = 'table';
        $params['limit'] = (string)$this->clampLimit($params['limit'] ?? null);
        if (isset($params['offset'])) {
            $params['offset'] = (string)max(0, (int)$params['offset']);
        }

        QueryProcessor::addThisPrintout($printouts, $params);
        $processed = QueryProcessor::getProcessedParams(
            $params,
            $printouts,
            true,
            QueryProcessor::SPECIAL_PAGE
        );

        $query = QueryProcessor::createQuery(
            $queryString,
            $processed,
            QueryProcessor::SPECIAL_PAGE,
            'table',
            $printouts
        );

        // Query the default store directly (never a configurable remote
        // source) and never go through a result printer.
        $queryResult = StoreFactory::getStore()->getQueryResult($query);

        $errors = [];
        foreach ($queryResult->getErrors() as $error) {
            $errors[] = $this->decodeError($error);
        }
        $errors = array_values(array_unique($errors));

        $printRequests = $queryResult->getPrintRequests();
        $labels = [];
        foreach ($printRequests as $printRequest) {
            if (!$printRequest->isMode(PrintRequest::PRINT_THIS)) {
                $labels[] = $this->getLabel($printRequest);
            }
        }

        $rows = [];

        foreach ($queryResult->getResults() as $subject) {
            // Dropped silently: reporting how many rows were removed would
            // let a caller probe whether hidden pages match a condition.
            if (!$this->canRead($user, $subject)) {
                continue;
            }

            $row = [
                'title' => $this->getSubjectText($subject),
                'printouts' => [],
            ];

            foreach ($printRequests as $printRequest) {
                if ($printRequest->isMode(PrintRequest::PRINT_THIS)) {
                    continue;
                }

                $resultArray = ResultArray::factory($subject, $printRequest, $queryResult);
                $values = [];
                while (($dataValue = $resultArray->getNextDataValue()) !== false) {
                    if (!$this->isDataItemReadable($user, $dataValue->getDataItem())) {
                        continue;
                    }
                    $values[] = (string)$dataValue->getWikiValue();
                }
                $row['printouts'][$this->getLabel($printRequest)] = $values;
            }

            $rows[] = $row;
        }

        return [
            'printouts' => array_values(array_unique($labels)),
            'rows' => $rows,
            'count' => count($rows),
            'has_more' => $queryResult->hasFurtherResults(),
            'errors' => $errors,
        ];
    }

    /**
     * Clamp the requested limit to [1, MAX_LIMIT], defaulting when absent
     * or non-numeric.
     *
     * @param mixed $limit
     * @return int
     */
    private function clampLimit($limit): int
    {
        if ($limit === null || !is_numeric(trim((string)$limit))) {
            return self::DEFAULT_LIMIT;
        }
        return max(1, min(self::MAX_LIMIT, (int)$limit));
    }

    /**
     * Turn an SMW query error (usually a JSON-encoded [type, key, ...params]
     * message) into plain text.
     *
     * Params are passed as plaintextParams so that text from the query
     * (e.g. "{{:Secret:Page}}") is never preprocessed/expanded.
     *
     * @param mixed $error
     * @return string
     */
    private function decodeError($error): string
    {
        $decoded = is_string($error) ? json_decode($error, true) : $error;
        if (!is_array($decoded)) {
            return is_string($error) ? $error : (string)json_encode($error);
        }

        if (isset($decoded[0]) && is_int($decoded[0])) {
            array_shift($decoded);
        }
        $key = array_shift($decoded);
        $params = array_map(
            static fn ($param) => is_scalar($param) ? (string)$param : (string)json_encode($param),
            array_values($decoded)
        );

        if (!is_string($key) || $key === '') {
            return (string)json_encode($error);
        }

        $message = wfMessage($key);
        if (!$message->exists()) {
            return trim($key . ' ' . implode(' ', $params));
        }
        return $message->plaintextParams($params)->text();
    }

    /**
     * Return a non-empty label for a print request.
     */
    private function getLabel(PrintRequest $printRequest): string
    {
        $label = trim((string)$printRequest->getLabel());
        if ($label === '') {
            $label = trim((string)$printRequest->getCanonicalLabel());
        }
        return $label;
    }

    /**
     * Prefixed title text of a result subject, including the subobject
     * fragment if any.
     */
    private function getSubjectText(WikiPage $subject): string
    {
        $title = $subject->getTitle();
        $text = $title ? $title->getPrefixedText() : $subject->getDBkey();
        if ((string)$subject->getSubobjectName() !== '') {
            $text .= '#' . $subject->getSubobjectName();
        }
        return $text;
    }

    /**
     * Whether the user may read the page a WikiPage data item points to.
     * Subobjects inherit the permission of their parent page.
     */
    private function canRead(UserIdentity $user, WikiPage $page): bool
    {
        // A subobject's title only adds a fragment, so this checks the
        // parent page.
        $title = $page->getTitle();
        return $title !== null
            && $this->permissionManager->quickUserCan('read', $user, $title);
    }

    /**
     * Check a printout value: page values must be readable, and container
     * values (records, subobject-like values) must not reference any
     * unreadable page.
     *
     * @param UserIdentity $user
     * @param DataItem|null $dataItem
     * @return bool
     */
    private function isDataItemReadable(UserIdentity $user, $dataItem): bool
    {
        if ($dataItem instanceof WikiPage) {
            return $this->canRead($user, $dataItem);
        }

        if ($dataItem instanceof Container) {
            $semanticData = $dataItem->getSemanticData();
            foreach ($semanticData->getProperties() as $property) {
                foreach ($semanticData->getPropertyValues($property) as $value) {
                    if ($value instanceof WikiPage && !$this->canRead($user, $value)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }
}
