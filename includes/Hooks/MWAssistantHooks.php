<?php

namespace MWAssistant\Hooks;

use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Request\WebRequest;
use MWAssistant\Config;

/**
 * Handles UI integration for MWAssistant.
 *
 * - Loads global search bar module for users allowed to use the assistant
 * - Loads chat/editor integration only on edit pages
 */
class MWAssistantHooks implements BeforePageDisplayHook
{

    /**
     * Add ResourceLoader modules when rendering a page.
     *
     * @inheritDoc
     */
    public function onBeforePageDisplay($out, $skin): void
    {
        if (!Config::isEnabled()) {
            return;
        }

        $user = $out->getUser();
        // The modules only talk to APIs gated on mwassistant-use; don't ship
        // UI that would fail for everyone else (including anonymous users).
        if (!$user->isRegistered() || !$out->getAuthority()->isAllowed('mwassistant-use')) {
            return;
        }

        // Global assistant module (search bar integration, shared UI)
        $out->addModules(['ext.mwassistant.global']);

        $title = $out->getTitle();
        if (!$title) {
            return;
        }

        // Determine whether we are on an edit interface
        $request = $out->getRequest();
        $action = $request instanceof WebRequest
            ? $request->getVal('action')
            : null;

        // 'edit' → normal edit mode
        // 'submit' → edit preview or post-edit confirmation
        if ($action === 'edit' || $action === 'submit') {
            $out->addModules(['ext.mwassistant.editor']);
        }
    }
}
