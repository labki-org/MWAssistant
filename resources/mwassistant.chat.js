(function (mw, $) {
    var api = new mw.Api();

    function renderUI() {
        var $container = $('#mwassistant-chat-container');
        var html =
            '<div class="mwassistant-chat">' +
            '<div class="mwassistant-chat-log" id="mwassistant-chat-log"></div>' +
            '<div class="mwassistant-chat-input">' +
            '<textarea id="mwassistant-chat-input-text" rows="3"></textarea>' +
            '<button id="mwassistant-chat-send">Send</button>' +
            '</div>' +
            '</div>';
        $container.html(html);
    }

    function appendMessage(role, content) {
        var $log = $('#mwassistant-chat-log');
        var cls = role === 'user' ? 'mwassistant-msg-user' : 'mwassistant-msg-assistant';
        var $msg = $('<div>').addClass('mwassistant-msg ' + cls);
        $msg.text(content);
        $log.append($msg);
        $log.scrollTop($log[0].scrollHeight);
    }

    function sendMessage() {
        var text = $('#mwassistant-chat-input-text').val().trim();
        if (!text) {
            return;
        }

        $('#mwassistant-chat-input-text').val('');
        appendMessage('user', text);

        var payload = {
            action: 'mwassistant-chat',
            format: 'json',
            // simplest: send only last user message; you can evolve to full history
            messages: JSON.stringify([{ role: 'user', content: text }]),
            token: mw.user.tokens.get('csrfToken')
        };

        $('#mwassistant-chat-send').prop('disabled', true);

        api.post(payload)
            .done(function (data) {
                var res = data['mwassistant-chat'];
                if (res && res.messages) {
                    // assume last message is assistant
                    var last = res.messages[res.messages.length - 1];
                    appendMessage(last.role, last.content);
                } else if (res && res.error) {
                    appendMessage('assistant', 'Error: ' + res.message);
                } else {
                    appendMessage('assistant', 'Error: malformed response.');
                }
            })
            .fail(function (code, result) {
                var msg = 'Error: request failed.';
                if (result && result.error && result.error.info) {
                    msg += ' ' + result.error.info;
                }
                appendMessage('assistant', msg);
            })
            .always(function () {
                $('#mwassistant-chat-send').prop('disabled', false);
            });
    }

    $(function () {
        // Only run on Special:MWAssistant
        if (mw.config.get('wgCanonicalSpecialPageName') !== 'MWAssistant') {
            return;
        }
        renderUI();

        $('#mwassistant-chat-send').on('click', sendMessage);
        $('#mwassistant-chat-input-text').on('keypress', function (e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
    });

}(mediaWiki, jQuery));
