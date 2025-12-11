<?php

namespace MWAssistant\Special;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;
use PermissionsError;

/**
 * Special page that provides the main interactive chat interface
 * between MediaWiki and the MCP server (LLM backend).
 *
 * This page intentionally outputs only a minimal container element.
 * The actual UI is rendered and managed by the ResourceLoader module:
 *      ext.mwassistant.chat
 *
 * Responsibilities:
 *  - Ensure the user has permission to access the assistant
 *  - Load JS module that renders the chat UI
 *  - Provide a clean, well-defined container for JS to populate
 */
class SpecialMWAssistant extends SpecialPage
{

    /**
     * Constructor.
     *
     * Register the special page under "Special:MWAssistant".
     * Permission `"mwassistant-use"` is enforced separately.
     */
    public function __construct()
    {
        parent::__construct('MWAssistant');
    }

    /**
     * Main entry point.
     *
     * @param string|null $par
     * @throws PermissionsError
     */
    public function execute($par)
    {
        $this->setHeaders();
        $this->requireUserPermissions();

        $out = $this->getOutput();
        $out->enableOOUI();

        // Load frontend chat JS module (does the heavy lifting).
        $out->addModules(['ext.mwassistant.chat']);

        // Minimal HTML root node for JS.
        $this->renderChatContainer();
    }

    /* ============================================================
       Permission Handling
       ============================================================ */

    /**
     * Enforce the custom permission for using the MW Assistant.
     *
     * This avoids mixing permission logic in execute().
     *
     * @throws PermissionsError
     */
    private function requireUserPermissions(): void
    {
        $user = $this->getUser();

        if (!$user->isAllowed('mwassistant-use')) {
            throw new PermissionsError('mwassistant-use');
        }
    }

    /* ============================================================
       Rendering Helpers
       ============================================================ */

    /**
     * Insert a placeholder DIV for the SPA-style chat UI.
     *
     * Keeping this isolated helps testing and readability,
     * and allows you to expand the UI later without touching execute().
     */
    private function renderChatContainer(): void
    {
        $this->getOutput()->addHTML(
            Html::rawElement(
                'div',
                [
                    'id' => 'mwassistant-chat-container',
                    'class' => 'mwassistant-chat-root'
                ],
                '' // JS app will mount here
            )
        );
    }
}
