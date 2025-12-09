<?php

namespace MWAssistant\Special;

use SpecialPage;
use Html;
use OutputPage;
use Skin;

class SpecialMWAssistant extends SpecialPage
{

    public function __construct()
    {
        parent::__construct('MWAssistant');
    }

    public function execute($par)
    {
        $this->setHeaders();
        $this->checkPermissions(); // Checks against the restriction set later or generic

        // Manual check for specific right if not set via restriction
        if (!$this->getUser()->isAllowed('mwassistant-use')) {
            throw new \PermissionsError('mwassistant-use');
        }

        $out = $this->getOutput();
        $out->addModules(['ext.mwassistant.chat']);

        $out->addHTML(
            Html::rawElement(
                'div',
                ['id' => 'mwassistant-chat-container'],
                '' // JS will populate this
            )
        );
    }
}
