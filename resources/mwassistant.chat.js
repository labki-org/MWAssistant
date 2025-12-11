/**
 * MWAssistant Chat Interface
 *
 * Provides:
 *  - Chat-style UI
 *  - Markdown-safe rendering
 *  - Code block wrappers with copy button
 *  - Session handling
 *  - Clean async request/response pipeline
 *
 * This file intentionally has no business logic.
 * It only handles the user interface & MediaWiki API requests.
 */
(function (mw, $) {

    /**
     * Utility: Generate UUID v4 (browser-safe)
     */
    function generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : ((r & 0x3) | 0x8);
            return v.toString(16);
        });
    }

    /**
     * Utility: Sleep helper (future streaming-ready)
     */
    function delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    /**
     * Chat Class
     */
    class MWAssistantChat {

        /**
         * @param {Object} config
         * @param {jQuery} config.$container
         * @param {string} [config.sessionId]
         * @param {Array} [config.systemPrompt]
         * @param {Function} [config.getExtraContext]
         */
        constructor(config) {
            this.$container = config.$container;
            this.sessionId = config.sessionId || generateUUID();
            this.systemPrompt = config.systemPrompt || [];
            this.getExtraContext = config.getExtraContext || (() => null);

            this.mwApi = new mw.Api();

            this.renderUI();
            this.bindEvents();
        }

        /* ------------------------------------------------------------------
         * UI Rendering
         * ------------------------------------------------------------------ */

        renderUI() {
            const html = `
                <div class="mwassistant-chat">
                    <div class="mwassistant-chat-header">
                        <h2>MWAssistant</h2>
                        <span 
                            class="mwassistant-log-notice"
                            id="mwassistant-chat-status"
                            title="Session: ${this.sessionId}"
                        >Chat is being logged</span>
                    </div>

                    <div class="mwassistant-chat-log" id="mwassistant-chat-log"></div>

                    <div class="mwassistant-chat-input">
                        <textarea 
                            id="mwassistant-chat-input-text" 
                            rows="3" 
                            placeholder="Ask for help..."
                        ></textarea>
                        <button id="mwassistant-chat-send">Send</button>
                    </div>
                </div>
            `;

            this.$container.html(html);
        }

        /* ------------------------------------------------------------------
         * Markdown Parser (Safe)
         * ------------------------------------------------------------------ */

        parseMarkdown(raw) {
            if (!raw) return "";

            // Escape HTML – prevents XSS entirely.
            let clean = $('<div>').text(raw).html();

            const codeBlocks = [];

            // Extract fenced blocks
            clean = clean.replace(/```([\s\S]*?)```/g, (match, code) => {
                const safe = $('<div>').text(code).text().trim();
                const index = codeBlocks.length;

                codeBlocks.push(`
                    <div class="mwassistant-code-wrapper">
                        <button class="mwassistant-copy-btn" title="Copy code">Copy</button>
                        <pre class="mwassistant-code-block"><code>${safe}</code></pre>
                    </div>
                `);

                return `___MWASSISTANT_CODE_BLOCK_${index}___`;
            });

            // Inline code
            clean = clean.replace(/`([^`]+)`/g, (_, txt) => {
                const safe = $('<div>').text(txt).html();
                return `<code class="mwassistant-inline-code">${safe}</code>`;
            });

            // Bold
            clean = clean.replace(/\*\*([^*]+)\*\*/g, "<b>$1</b>");

            // Markdown links
            clean = clean.replace(
                /\[([^\]]+)\]\(([^)]+)\)/g,
                (_, label, url) => `<a href="${url}" target="_blank" rel="noopener">${label}</a>`
            );

            // Wiki links
            clean = clean.replace(
                /\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/g,
                (_, page, label) => {
                    const url = mw.util.getUrl(page);
                    return `<a href="${url}" title="${page}">${label || page}</a>`;
                }
            );

            // Restore code blocks
            clean = clean.replace(/___MWASSISTANT_CODE_BLOCK_(\d+)___/g, (_, idx) => {
                return codeBlocks[parseInt(idx, 10)];
            });

            return clean;
        }

        /* ------------------------------------------------------------------
         * Message Rendering
         * ------------------------------------------------------------------ */

        appendMessage(role, content) {
            const $log = this.$container.find('#mwassistant-chat-log');
            const cls = role === 'user' ? 'mwassistant-msg-user' : 'mwassistant-msg-assistant';

            const $msg = $('<div>')
                .addClass(`mwassistant-msg ${cls}`)
                .html(this.parseMarkdown(content));

            $log.append($msg);

            // Auto-scroll to bottom
            $log.scrollTop($log.prop('scrollHeight'));
        }

        /* ------------------------------------------------------------------
         * Event Binding
         * ------------------------------------------------------------------ */

        bindEvents() {
            const $root = this.$container;

            // Send button
            $root.on('click', '#mwassistant-chat-send', () => this.sendMessage());

            // Enter key = send
            $root.on('keypress', '#mwassistant-chat-input-text', (e) => {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });

            // Copy buttons (delegated)
            $root.on('click', '.mwassistant-copy-btn', function () {
                const $btn = $(this);
                const code = $btn.siblings('pre').text();

                navigator.clipboard?.writeText(code).then(() => {
                    $btn.text('Copied!');
                    setTimeout(() => $btn.text('Copy'), 1500);
                }).catch(() => {
                    $btn.text('Error');
                    setTimeout(() => $btn.text('Copy'), 1500);
                });
            });
        }

        /* ------------------------------------------------------------------
         * Message Sending
         * ------------------------------------------------------------------ */

        async sendMessage() {
            const $input = this.$container.find('#mwassistant-chat-input-text');
            const text = $input.val().trim();
            if (!text) return;

            $input.val('');
            this.appendMessage('user', text);

            const payload = this.buildPayload(text);

            const $btn = this.$container.find('#mwassistant-chat-send');
            $btn.prop('disabled', true);

            try {
                const data = await this.mwApi.post(payload);
                this.handleResponse(data);
            } catch (err) {
                console.error("MWAssistant API error:", err);
                this.appendMessage('assistant', 'Error: failed to reach server.');
            } finally {
                $btn.prop('disabled', false);
            }
        }

        /* ------------------------------------------------------------------
         * Request Payload Assembly
         * ------------------------------------------------------------------ */

        buildPayload(userText) {
            const messages = [];

            // First-time system prompt gets injected only once
            if (this.systemPrompt.length > 0) {
                messages.push(...this.systemPrompt);
                this.systemPrompt = [];
            }

            // Optional extra context (invisible system layer)
            const extra = this.getExtraContext();
            if (extra) {
                messages.push({
                    role: 'system',
                    content: 'Context:\n' + extra
                });
            }

            // User message
            messages.push({
                role: 'user',
                content: userText
            });

            return {
                action: 'mwassistant-chat',
                format: 'json',
                messages: JSON.stringify(messages),
                session_id: this.sessionId,
                token: mw.user.tokens.get('csrfToken')
            };
        }

        /* ------------------------------------------------------------------
         * Response Handling
         * ------------------------------------------------------------------ */

        handleResponse(data) {
            const result = data['mwassistant-chat'];

            if (!result) {
                this.appendMessage('assistant', 'Error: malformed response.');
                return;
            }

            // Add link to log if present
            if (result.log_info?.url) {
                const $status = this.$container.find('#mwassistant-chat-status');
                const linkHtml =
                    `<a href="${result.log_info.url}" target="_blank" class="mwassistant-log-notice" id="mwassistant-chat-status">Logs auto-saved</a>`;

                // Replace span with link, or update existing link
                if ($status.is('span')) {
                    $status.replaceWith(linkHtml);
                } else {
                    $status.attr('href', result.log_info.url);
                }
            }

            // Show assistant message
            if (result.messages?.length) {
                const last = result.messages[result.messages.length - 1];
                this.appendMessage(last.role, last.content);
            } else if (result.error) {
                this.appendMessage('assistant', 'Error: ' + (result.message || 'Unknown'));
            } else {
                this.appendMessage('assistant', 'Error: malformed response.');
            }
        }
    }

    /* ----------------------------------------------------------------------
     * Export & Auto-init
     * ---------------------------------------------------------------------- */

    mw.mwAssistant = mw.mwAssistant || {};
    mw.mwAssistant.Chat = MWAssistantChat;

    $(function () {
        const $root = $('#mwassistant-chat-container');
        if ($root.length) {
            const chat = new MWAssistantChat({ $container: $root });

            // Pre-fill message from ?q= param
            const q = mw.util.getParamValue('q');
            if (q) {
                $('#mwassistant-chat-input-text').val(q);
            }
        }
    });

}(mediaWiki, jQuery));
