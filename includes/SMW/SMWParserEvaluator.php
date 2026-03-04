<?php

namespace MWAssistant\SMW;

use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;

/**
 * Service to evaluate SMW queries by parsing them as wikitext.
 * This ensures consistency with inline {{#ask:...}} behavior.
 */
class SMWParserEvaluator
{
    /**
     * Evaluate an SMW query string by wrapping it in {{#ask:...}}
     * and parsing it.
     *
     * @param UserIdentity $user The context user
     * @param string $queryArgs The inner arguments for #ask (e.g. "[[Cat:X]]|?Prop")
     * @return ParserOutput The full parser output (use getText() for HTML, getLinks() for subjects)
     */
    /** @var int Default limit for SMW query results */
    private const SMW_RESULT_LIMIT = 100;

    public function evaluate(UserIdentity $user, string $queryArgs): ParserOutput
    {
        // Append a result limit if the query doesn't already specify one
        $trimmed = trim($queryArgs);
        if (!preg_match('/\|limit\s*=/i', $trimmed)) {
            $trimmed .= '|limit=' . self::SMW_RESULT_LIMIT;
        }

        // Construct the full parser function
        $wikitext = "{{#ask:" . $trimmed . "}}";

        // Parse it using the standard MediaWiki parser
        $parser = MediaWikiServices::getInstance()->getParser();
        $opt = ParserOptions::newFromUser($user);

        // We use a dummy title for parsing context
        $title = Title::newFromText('MWAssistantSMWQuery');

        return $parser->parse($wikitext, $title, $opt);
    }
}
