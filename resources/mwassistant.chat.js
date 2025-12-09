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

    function parseMarkdown(text) {
        // 1. Escape HTML to prevent XSS
        var clean = $('<div>').text(text).html();

        // Placeholder for code blocks to prevent double parsing
        var codeBlocks = [];

        // 2. Extract Code blocks: ```code```
        clean = clean.replace(/```([\s\S]*?)```/g, function (match, code) {
            // Trim leading/trailing whitespace to prevent extra lines in <pre>
            code = code.trim();
            codeBlocks.push('<pre class="mwassistant-code-block"><code>' + code + '</code></pre>');
            return '___MWASSISTANT_CODE_BLOCK_' + (codeBlocks.length - 1) + '___';
        });

        // 3. Inline code: `code`
        clean = clean.replace(/`([^`]+)`/g, function (match, code) {
            return '<code class="mwassistant-inline-code">' + code + '</code>';
        });

        // 4. Bold: **text**
        clean = clean.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>');

        // 5. Markdown links: [text](url)
        clean = clean.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank">$1</a>');

        // 6. Wiki links: [[Page Title]] or [[Page Title|Label]]
        clean = clean.replace(/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/g, function (match, page, label) {
            var url = mw.util.getUrl(page);
            return '<a href="' + url + '" title="' + page + '">' + (label || page) + '</a>';
        });

        // 7. Restore code blocks
        clean = clean.replace(/___MWASSISTANT_CODE_BLOCK_(\d+)___/g, function (match, index) {
            return codeBlocks[parseInt(index, 10)];
        });

        return clean;
    }

    function appendMessage(role, content) {
        var $log = $('#mwassistant-chat-log');
        var cls = role === 'user' ? 'mwassistant-msg-user' : 'mwassistant-msg-assistant';
        var $msg = $('<div>').addClass('mwassistant-msg ' + cls);

        // Use .html() with the parsed content
        $msg.html(parseMarkdown(content));

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
            session_id: currentSessionId,
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

    var currentSessionId = null;

    function generateUUID() {
        // Simple UUID v4 generator
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0, v = c == 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    $(function () {
        // Module is only loaded on Special:MWAssistant, so we can run immediately.
        console.log('MWAssistant chat module loaded.');

        // Initialize session ID
        currentSessionId = generateUUID();
        console.log('Session ID:', currentSessionId);

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
