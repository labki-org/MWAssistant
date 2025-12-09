(function (mw, $) {
    var api = new mw.Api();

    function addButton() {
        var $toolbar = $('#wpTextbox1').closest('form').find('.toolbar');
        // If no toolbar (depending on editor), you can append near textarea
        if (!$toolbar.length) {
            // Fallback for skins or editors without standard toolbar class
            $toolbar = $('#wpTextbox1').before('<div class="mwassistant-editor-tools"></div>').prev();
        }

        var $btn = $('<button type="button">AI Assist</button>')
            .addClass('mwassistant-editor-button')
            .on('click', onAssistClick);

        $toolbar.append($btn);
    }

    function onAssistClick() {
        var $textarea = $('#wpTextbox1');
        var text = $textarea.val();
        var selection = getSelectionText($textarea[0]);
        var prompt = selection || text;
        if (!prompt) {
            alert('Please enter some text or select text to assist with.');
            return;
        }

        var task = window.prompt('Describe what you want the AI to do (e.g. "improve wording", "summarize this section"):', 'improve wording');
        if (task === null) {
            return; // Cancelled
        }

        var messages = [
            {
                role: 'system',
                content: 'You are assisting with MediaWiki wikitext editing.'
            },
            {
                role: 'user',
                content: 'Task: ' + task + '\n\nText:\n' + prompt
            }
        ];

        // Indicate loading
        var $btn = $('.mwassistant-editor-button');
        var originalText = $btn.text();
        $btn.text('Thinking...').prop('disabled', true);

        api.post({
            action: 'mwassistant-chat',
            format: 'json',
            messages: JSON.stringify(messages),
            token: mw.user.tokens.get('csrfToken')
        })
            .done(function (data) {
                var res = data['mwassistant-chat'];
                if (res && res.messages) {
                    var last = res.messages[res.messages.length - 1];
                    showSuggestionDialog(last.content, $textarea, selection);
                } else if (res && res.error) {
                    alert('MWAssistant Error: ' + res.message);
                } else {
                    alert('MWAssistant: malformed response.');
                }
            })
            .fail(function (code, result) {
                var msg = 'Request failed.';
                if (result && result.error && result.error.info) {
                    msg += ' ' + result.error.info;
                }
                alert('MWAssistant: ' + msg);
            })
            .always(function () {
                $btn.text(originalText).prop('disabled', false);
            });
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

    function showSuggestionDialog(suggestion, $textarea, selection) {
        // Minimal: show confirm dialog, then replace selection
        // In a real app we might use OOUI or a modal
        if (!window.confirm('Replace selection/text with AI suggestion?\n\n' + suggestion)) {
            return;
        }

        var textarea = $textarea[0];
        if (selection) {
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            var before = textarea.value.substring(0, start);
            var after = textarea.value.substring(end);
            textarea.value = before + suggestion + after;
        } else {
            // Append if no selection, or maybe replace all? 
            // The prompt says "Replace selection with AI suggestion?". 
            // If passed text (no selection), logic in onAssistClick uses full text as prompt. 
            // So replacement should probably replace all if no selection was active, or append?
            // "if ( selection || text )" -> if prompt was full text, likely we want to replace it or refine it.
            // But let's check: user selected nothing. Prompt = text.
            // Logic: if (selection) replace selection. Else append? Or replace all?
            // The user's sample code:
            /*
            if ( selection ) { ... } else {
                textarea.value = textarea.value + '\n\n' + suggestion;
            }
            */
            // I'll stick to the user's sample logic (append) for safety, but maybe improving slightly.
            textarea.value = textarea.value + '\n\n' + suggestion;
        }
    }

    $(function () {
        // Wait for toolbar or just run
        // Often toolbar loads async, so might need hook.
        // But for now, document.ready.
        if (mw.config.get('wgAction') !== 'edit' && mw.config.get('wgAction') !== 'submit') {
            return;
        }
        addButton();
    });

}(mediaWiki, jQuery));
