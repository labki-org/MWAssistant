<?php

namespace MWAssistant\Hooks;

use MediaWiki\Output\OutputPage;
use Skin;

class MWAssistantHooks
{

    public static function onBeforePageDisplay(OutputPage $out, Skin $skin): void
    {
        if (!$out->getUser()->isRegistered()) {
            return;
        }

        // Always load the global module for registered users (search bar integration)
        $out->addModules(['ext.mwassistant.global']);

        $title = $out->getTitle();
        // Check if we are on an edit page.
        // 'action=edit' or 'action=submit' (often 'submit' when previewing).
        // Using Action::getActionName is better but direct request check works for 'edit' param typically.
        // We do NOT check isContentPage() because we want this on User pages, Templates, etc.
        $action = $out->getRequest()->getVal('action');
        if ($title && ($action === 'edit' || $action === 'submit')) {
            $out->addModules(['ext.mwassistant.editor']);
        }
    }
}
