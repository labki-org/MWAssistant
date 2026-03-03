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
    public function evaluate(UserIdentity $user, string $queryArgs): ParserOutput
    {
        // Construct the full parser function
        $wikitext = "{{#ask:" . trim($queryArgs) . "}}";

        // Parse it using the standard MediaWiki parser
        $parser = MediaWikiServices::getInstance()->getParser();
        $opt = ParserOptions::newFromUser($user);

        // We use a dummy title for parsing context
        $title = Title::newFromText('MWAssistantSMWQuery');

        return $parser->parse($wikitext, $title, $opt);
    }
}
