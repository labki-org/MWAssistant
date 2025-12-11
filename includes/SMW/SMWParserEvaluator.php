<?php

namespace MWAssistant\SMW;

use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
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
     * @return string The rendered output (HTML or plain text depending on format)
     */
    public function evaluate(UserIdentity $user, string $queryArgs): string
    {
        // Construct the full parser function
        $wikitext = "{{#ask: " . $queryArgs . "}}";

        // Parse it using the standard MediaWiki parser
        $parser = MediaWikiServices::getInstance()->getParser();
        $opt = ParserOptions::newFromUser($user);

        // We use a dummy title for parsing context
        $title = Title::newFromText('MWAssistantSMWQuery');

        $output = $parser->parse($wikitext, $title, $opt);

        // Return the raw text output validation
        return $output->getText();
    }
}
