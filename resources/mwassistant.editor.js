(function (mw, $) {
    var api = new mw.Api();

    function addButton() {
        console.log('MWAssistant Editor: Attempting to add button...');
        var $toolbar = $('#wpTextbox1').closest('form').find('.toolbar');

        // WikiEditor support
        if (!$toolbar.length) {
            $toolbar = $('.wikiEditor-ui-toolbar');
        }

        // Vector 2022 / OOUI toolbar fallback
        if (!$toolbar.length) {
            $toolbar = $('.oo-ui-toolbar-bar');
        }

        // If no toolbar (depending on editor), you can append near textarea
        if (!$toolbar.length) {
            console.log('MWAssistant Editor: No toolbar found, falling back to #wpTextbox1');
            // Fallback for skins or editors without standard toolbar class
            $toolbar = $('#wpTextbox1').before('<div class="mwassistant-editor-tools"></div>').prev();
        }

        if (!$toolbar.length && $('#wpTextbox1').length === 0) {
            console.error('MWAssistant Editor: Could not find editor surface (#wpTextbox1)!');
            return;
        }

        console.log('MWAssistant Editor: Injecting button into:', $toolbar);

        var $btn = $('<button type="button">Ask Assistant</button>')
            .addClass('mwassistant-editor-button mw-ui-button')
            .on('click', toggleSidebar);

        if ($toolbar.hasClass('oo-ui-toolbar-bar')) {
            // OOUI needs special handling or just prepend?
            $toolbar.prepend($btn);
            // Ensure z-index or styling matches
            $btn.css({ 'z-index': 999, 'position': 'relative', 'margin': '5px' });
        } else {
            $toolbar.append($btn);
        }
    }

    var sidebarInitialized = false;
    var $sidebar = null;
    var chatInstance = null;

    function toggleSidebar(e) {
        e.preventDefault();

        if (!sidebarInitialized) {
            initSidebar();
        }

        if ($sidebar.is(':visible')) {
            $sidebar.hide();
            $('#wpTextbox1').closest('.mwassistant-editor-wrapper').removeClass('with-sidebar');
        } else {
            $sidebar.show();
            // Wrap editor to handle layout if possible, or just exact positioning
            // For simplicity, fixed position sidebar on the right
            $('#wpTextbox1').focus(); // Return focus?
        }
    }

    function initSidebar() {
        // Create sidebar container
        $sidebar = $('<div id="mwassistant-editor-sidebar"></div>');

        // Add close button
        var $closeParams = $('<div class="mwassistant-sidebar-header"><span class="mwassistant-sidebar-title">Assistant</span><button type="button" class="mwassistant-sidebar-close">×</button></div>');
        $closeParams.find('.mwassistant-sidebar-close').on('click', function () {
            $sidebar.hide();
        });
        $sidebar.append($closeParams);

        // Chat container
        var $chatContainer = $('<div id="mwassistant-editor-chat"></div>');
        $sidebar.append($chatContainer);

        $('body').append($sidebar);

        // Initialize Chat
        if (mw.mwAssistant && mw.mwAssistant.Chat) {
            chatInstance = new mw.mwAssistant.Chat({
                $container: $chatContainer,
                systemPrompt: [
                    {
                        role: 'system',
                        content: 'You are an intelligent MediaWiki assistant helping a user edit a wiki page. ' +
                            'Your PRIMARY goal is to provide specific MediaWiki syntax help, such as Semantic MediaWiki (SMW) queries, template usage, table formatting, and wikitext markup (links, categories, etc.). ' +
                            'Focus on generating correct wikitext code snippets that the user can copy. ' +
                            'And provide explanations for the code snippets.' +
                            'Do NOT execute any tool calls to edit the page directly. The user will copy-paste your suggestions.'
                    }
                ],
                getExtraContext: function () {
                    var $textarea = $('#wpTextbox1');
                    var content = $textarea.val() || '';
                    var selection = getSelectionText($textarea[0]);

                    var context = "Current Page Title: " + mw.config.get('wgPageName') + "\n";
                    if (selection) {
                        context += "User Selection:\n" + selection + "\n\n";
                    }

                    // Always send full content context if possible, truncated if very large
                    if (content) {
                        context += "Full Page Content (Truncated if too large):\n" + content.substring(0, 10000);
                    }
                    return context;
                }
            });
        } else {
            $chatContainer.text("Error: MWAssistant Chat module not loaded.");
        }

        sidebarInitialized = true;
    }

    function getSelectionText(textarea) {
        if (textarea.selectionStart !== undefined) {
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            if (start !== end) {
                return textarea.value.substring(start, end);
            }
        }
        return '';
    }

    $(function () {
        // Wait for toolbar or just run
        if (mw.config.get('wgAction') !== 'edit' && mw.config.get('wgAction') !== 'submit') {
            return;
        }
        addButton();
    });

}(mediaWiki, jQuery));
