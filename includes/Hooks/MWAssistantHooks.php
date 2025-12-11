<?php

namespace MWAssistant\Hooks;

use MediaWiki\Output\OutputPage;
use MediaWiki\Request\WebRequest;
use Skin;

/**
 * Handles UI integration for MWAssistant.
 *
 * - Loads global search bar module for logged-in users
 * - Loads chat/editor integration only on edit pages
 */
class MWAssistantHooks
{

    /**
     * Add ResourceLoader modules when rendering a page.
     *
     * @param OutputPage $out
     * @param Skin $skin
     */
    public static function onBeforePageDisplay(OutputPage $out, Skin $skin): void
    {

        $user = $out->getUser();
        if (!$user->isRegistered()) {
            return; // Avoid providing UI to anonymous users
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
