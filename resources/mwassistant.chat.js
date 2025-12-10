(function (mw, $) {
    var api = new mw.Api();

    /**
     * MWAssistantChat Class
     * @param {Object} config
     * @param {jQuery} config.$container Container element
     * @param {string} [config.sessionId]
     * @param {Array} [config.systemPrompt] Array of message objects
     * @param {Function} [config.getExtraContext] Function returning string/obj to append to user message (invisible to user, system instruction)
     */
    function MWAssistantChat(config) {
        this.$container = config.$container;
        this.sessionId = config.sessionId || this.generateUUID();
        this.systemPrompt = config.systemPrompt || [];
        this.getExtraContext = config.getExtraContext || function () { return null; };

        this.renderUI();
        this.bindEvents();
    }

    MWAssistantChat.prototype.generateUUID = function () {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0, v = c == 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    };

    MWAssistantChat.prototype.renderUI = function () {
        var html =
            '<div class="mwassistant-chat">' +
            '<div class="mwassistant-chat-header">' +
            '<h2>MWAssistant</h2>' +
            '<span class="mwassistant-log-notice" id="mwassistant-chat-status" title="Session: ' + this.sessionId + '">Chat is being logged</span>' +
            '</div>' +
            '<div class="mwassistant-chat-log" id="mwassistant-chat-log"></div>' +
            '<div class="mwassistant-chat-input">' +
            '<textarea id="mwassistant-chat-input-text" rows="3" placeholder="Ask for help..."></textarea>' +
            '<button id="mwassistant-chat-send">Send</button>' +
            '</div>' +
            '</div>';
        this.$container.html(html);
    };

    MWAssistantChat.prototype.parseMarkdown = function (text) {
        // 1. Escape HTML to prevent XSS
        var clean = $('<div>').text(text).html();

        // Placeholder for code blocks
        var codeBlocks = [];

        // 2. Extract Code blocks: ```code```
        clean = clean.replace(/```([\s\S]*?)```/g, function (match, code) {
            code = code.trim();
            // Wrap in a relative container with a copy button
            var html = '<div class="mwassistant-code-wrapper">' +
                '<button class="mwassistant-copy-btn" title="Copy code">Copy</button>' +
                '<pre class="mwassistant-code-block"><code>' + code + '</code></pre>' +
                '</div>';
            codeBlocks.push(html);
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
    };

    MWAssistantChat.prototype.appendMessage = function (role, content) {
        var $log = this.$container.find('#mwassistant-chat-log');
        var cls = role === 'user' ? 'mwassistant-msg-user' : 'mwassistant-msg-assistant';
        var $msg = $('<div>').addClass('mwassistant-msg ' + cls);
        $msg.html(this.parseMarkdown(content));
        $log.append($msg);
        $log.scrollTop($log[0].scrollHeight);
    };

    MWAssistantChat.prototype.bindEvents = function () {
        var self = this;
        this.$container.find('#mwassistant-chat-send').on('click', function () { self.sendMessage(); });
        this.$container.find('#mwassistant-chat-input-text').on('keypress', function (e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                self.sendMessage();
            }
        });

        // Use delegation for dynamic copy buttons
        this.$container.on('click', '.mwassistant-copy-btn', function () {
            var $btn = $(this);
            var code = $btn.siblings('pre').text(); // Get raw text from pre

            // Clipboard API or fallback
            if (navigator.clipboard) {
                navigator.clipboard.writeText(code).then(function () {
                    var original = $btn.text();
                    $btn.text('Copied!');
                    setTimeout(function () { $btn.text(original); }, 2000);
                }).catch(function (err) {
                    console.error('Failed to copy: ', err);
                    $btn.text('Error');
                });
            } else {
                // Fallback for older browsers / insecure context
                var textArea = document.createElement("textarea");
                textArea.value = code;
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    $btn.text('Copied!');
                } catch (err) {
                    $btn.text('Error');
                }
                document.body.removeChild(textArea);
                setTimeout(function () { $btn.text('Copy'); }, 2000);
            }
        });
    };

    MWAssistantChat.prototype.sendMessage = function () {
        var $input = this.$container.find('#mwassistant-chat-input-text');
        var text = $input.val().trim();
        if (!text) return;

        $input.val('');
        this.appendMessage('user', text);

        var messages = [];

        if (this.systemPrompt && this.systemPrompt.length > 0) {
            messages = messages.concat(this.systemPrompt);
            this.systemPrompt = [];
        }

        var extraContext = this.getExtraContext();
        if (extraContext) {
            messages.push({ role: 'system', content: 'Context:\n' + extraContext });
        }

        messages.push({ role: 'user', content: text });

        var payload = {
            action: 'mwassistant-chat',
            format: 'json',
            messages: JSON.stringify(messages),
            session_id: this.sessionId,
            token: mw.user.tokens.get('csrfToken')
        };

        var $btn = this.$container.find('#mwassistant-chat-send');
        $btn.prop('disabled', true);

        var self = this;
        api.post(payload)
            .done(function (data) {
                var res = data['mwassistant-chat'];
                if (res && res.log_info && res.log_info.url) {
                    var $status = self.$container.find('#mwassistant-chat-status');
                    if ($status.is('span')) {
                        $status.replaceWith('<a href="' + res.log_info.url + '" target="_blank" class="mwassistant-log-notice" id="mwassistant-chat-status">Logs auto-saved</a>');
                    } else {
                        $status.attr('href', res.log_info.url);
                    }
                }

                if (res && res.messages) {
                    var last = res.messages[res.messages.length - 1];
                    self.appendMessage(last.role, last.content);
                } else if (res && res.error) {
                    self.appendMessage('assistant', 'Error: ' + res.message);
                } else {
                    self.appendMessage('assistant', 'Error: malformed response.');
                }
            })
            .fail(function (code, result) {
                var msg = 'Error: request failed.';
                if (result && result.error && result.error.info) {
                    msg += ' ' + result.error.info;
                }
                self.appendMessage('assistant', msg);
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    };

    // Expose globally
    mw.mwAssistant = mw.mwAssistant || {};
    mw.mwAssistant.Chat = MWAssistantChat;

    // Auto-init for Special Page
    $(function () {
        if ($('#mwassistant-chat-container').length) {
            new MWAssistantChat({
                $container: $('#mwassistant-chat-container')
            });

            // Check for 'q' parameter in URL to pre-fill the chat
            var preQuery = mw.util.getParamValue('q');
            if (preQuery) {
                var $input = $('#mwassistant-chat-input-text');
                $input.val(preQuery);
            }
        }
    });

}(mediaWiki, jQuery));
