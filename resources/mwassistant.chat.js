/**
 * MWAssistant Chat Interface
 *
 * Provides:
 *  - ChatGPT-style sidebar with session list
 *  - Chat-style UI with markdown rendering
 *  - Code block wrappers with copy button
 *  - Session switching and management
 *  - Clean async request/response pipeline
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
     * Format a date for display
     */
    function formatDate(isoString) {
        if (!isoString) return '';
        const date = new Date(isoString);
        const now = new Date();
        const diff = now - date;
        
        // Less than 24 hours: show time
        if (diff < 86400000) {
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
        // Less than 7 days: show day name
        if (diff < 604800000) {
            return date.toLocaleDateString([], { weekday: 'short' });
        }
        // Otherwise: show date
        return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
    }

    /**
     * Chat Class
     */
    class MWAssistantChat {

        /**
         * @param {Object} config
         * @param {jQuery} config.$container
         * @param {string} [config.context] 'chat' or 'editor'
         * @param {Function} [config.getExtraContext]
         * @param {boolean} [config.hideSessions] Skip rendering the session list
         *   and the per-message refetch. Used when the chat is embedded in a
         *   constrained host (e.g., the editor sidebar) where Special:MWAssistant
         *   is the canonical place to browse history.
         */
        constructor(config) {
            this.$container = config.$container;
            this.context = config.context || 'chat';
            this.getExtraContext = config.getExtraContext || (() => null);
            this.hideSessions = !!config.hideSessions;

            this.sessionId = null;  // Will be set when session is loaded/created
            this.sessions = [];
            this.mwApi = new mw.Api();

            // Per-stream UI state — maps tool call_id -> jQuery node so we can
            // upgrade tool_start placeholders into final tool_result rows.
            this._activeToolNodes = new Map();

            this.renderUI();
            this.bindEvents();
            this.loadSessions();
        }

        /* ------------------------------------------------------------------
         * UI Rendering
         * ------------------------------------------------------------------ */

        renderUI() {
            const html = `
                <div class="mwassistant-layout">
                    <div class="mwassistant-sidebar">
                        <div class="mwassistant-sidebar-header">
                            <button class="mwassistant-new-chat" id="mwassistant-new-chat">
                                <span class="mwassistant-icon">+</span> New Chat
                            </button>
                        </div>
                        <div class="mwassistant-session-list" id="mwassistant-session-list">
                            <div class="mwassistant-loading">Loading sessions...</div>
                        </div>
                    </div>
                    <div class="mwassistant-chat">
                        <div class="mwassistant-chat-header">
                            <h2 id="mwassistant-chat-title">New Chat</h2>
                        </div>

                        <div class="mwassistant-chat-log" id="mwassistant-chat-log">
                            <div class="mwassistant-welcome">
                                <p>Welcome to MWAssistant! Ask me anything about this wiki.</p>
                            </div>
                        </div>

                        <div class="mwassistant-chat-input">
                            <textarea
                                id="mwassistant-chat-input-text"
                                rows="3"
                                placeholder="What's on your mind?"
                            ></textarea>
                            <button id="mwassistant-chat-send">Send</button>
                        </div>
                    </div>
                </div>
            `;

            this.$container.html(html);
            this.$log = this.$container.find('#mwassistant-chat-log');
        }

        /* ------------------------------------------------------------------
         * Session List Management
         * ------------------------------------------------------------------ */

        async loadSessions() {
            if (this.hideSessions) {
                return;
            }
            try {
                const data = await this.mwApi.post({
                    action: 'mwassistant-sessions',
                    command: 'list',
                    token: mw.user.tokens.get('csrfToken')
                });
                
                const result = data['mwassistant-sessions'];
                if (result && !result.error) {
                    this.sessions = Array.isArray(result) ? result : [];
                    this.renderSessionList();
                } else {
                    this.showSessionError(result?.message || 'Failed to load sessions');
                }
            } catch (err) {
                console.error('Failed to load sessions:', err);
                this.showSessionError('Failed to load sessions');
            }
        }

        renderSessionList() {
            const $list = this.$container.find('#mwassistant-session-list');
            
            if (this.sessions.length === 0) {
                $list.html('<div class="mwassistant-empty">No previous chats</div>');
                return;
            }

            const items = this.sessions.map(s => {
                const safeId = this.escapeHtml(s.session_id || '');
                const safeTitle = this.escapeHtml(s.title || 'Untitled');
                const safeDate = this.escapeHtml(formatDate(s.updated_at));
                const activeClass = s.session_id === this.sessionId ? 'active' : '';
                return `
                <div class="mwassistant-session-item ${activeClass}"
                     data-session-id="${safeId}">
                    <div class="mwassistant-session-info">
                        <span class="mwassistant-session-title">${safeTitle}</span>
                        <span class="mwassistant-session-date">${safeDate}</span>
                    </div>
                    <button class="mwassistant-session-delete" data-session-id="${safeId}" title="Delete">×</button>
                </div>`;
            }).join('');

            $list.html(items);
        }

        showSessionError(message) {
            const $list = this.$container.find('#mwassistant-session-list');
            $list.html(`<div class="mwassistant-error">${this.escapeHtml(message)}</div>`);
        }

        async loadSession(sessionId) {
            this._activeToolNodes.clear();
            const $log = this.$log;
            $log.html('<div class="mwassistant-loading">Loading conversation...</div>');

            try {
                const data = await this.mwApi.post({
                    action: 'mwassistant-sessions',
                    command: 'get',
                    session_id: sessionId,
                    token: mw.user.tokens.get('csrfToken')
                });

                const result = data['mwassistant-sessions'];
                if (result && !result.error && result.messages) {
                    this.sessionId = sessionId;
                    this.$container.find('#mwassistant-chat-title').text(result.title || 'Chat');
                    
                    $log.empty();
                    result.messages.forEach(msg => {
                        this.appendMessage(msg.role, msg.content);
                    });

                    this.renderSessionList();  // Update active state
                } else {
                    $log.html('<div class="mwassistant-error">Failed to load conversation</div>');
                }
            } catch (err) {
                console.error('Failed to load session:', err);
                $log.html('<div class="mwassistant-error">Failed to load conversation</div>');
            }
        }

        async deleteSession(sessionId) {
            if (!confirm('Delete this conversation?')) return;

            try {
                await this.mwApi.post({
                    action: 'mwassistant-sessions',
                    command: 'delete',
                    session_id: sessionId,
                    token: mw.user.tokens.get('csrfToken')
                });

                // Remove from local list
                this.sessions = this.sessions.filter(s => s.session_id !== sessionId);
                this.renderSessionList();

                // If we deleted the current session, start a new one
                if (this.sessionId === sessionId) {
                    this.startNewChat();
                }
            } catch (err) {
                console.error('Failed to delete session:', err);
                alert('Failed to delete conversation');
            }
        }

        startNewChat() {
            this._activeToolNodes.clear();
            this.sessionId = null;
            this.$container.find('#mwassistant-chat-title').text('New Chat');
            this.$log.html(`
                <div class="mwassistant-welcome">
                    <p>Welcome to MWAssistant! Ask me anything about this wiki.</p>
                </div>
            `);
            this.renderSessionList();  // Clear active state
        }

        /* ------------------------------------------------------------------
         * Markdown Parser (Safe)
         * ------------------------------------------------------------------ */

        parseMarkdown(raw) {
            if (!raw) return "";

            // Escape HTML – prevents XSS entirely.
            let clean = this.escapeHtml(raw);

            const codeBlocks = [];

            // Extract fenced blocks
            clean = clean.replace(/```([\s\S]*?)```/g, (match, code) => {
                const index = codeBlocks.length;
                codeBlocks.push(`
                    <div class="mwassistant-code-wrapper">
                        <button class="mwassistant-copy-btn" title="Copy code">Copy</button>
                        <pre class="mwassistant-code-block"><code>${code.trim()}</code></pre>
                    </div>
                `);
                return `___MWASSISTANT_CODE_BLOCK_${index}___`;
            });

            // Inline code (content already HTML-escaped above)
            clean = clean.replace(/`([^`]+)`/g, (_, txt) => {
                return `<code class="mwassistant-inline-code">${txt}</code>`;
            });

            // Bold
            clean = clean.replace(/\*\*([^*]+)\*\*/g, "<b>$1</b>");

            // Markdown links — only allow safe URL schemes to prevent
            // javascript:/data: injection through model output.
            clean = clean.replace(
                /\[([^\]]+)\]\(([^)]+)\)/g,
                (match, label, url) => {
                    if (!this.isSafeUrl(url)) {
                        return match;
                    }
                    return `<a href="${url}" target="_blank" rel="noopener">${label}</a>`;
                }
            );

            // Wiki links – page comes from already-escaped text so it is safe
            // to interpolate, but we still pass it through escapeHtml for the
            // title attribute to be defensive about HTML entities.
            clean = clean.replace(
                /\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/g,
                (_, page, label) => {
                    const url = mw.util.getUrl(page);
                    return `<a href="${url}" title="${this.escapeHtml(page)}">${label || page}</a>`;
                }
            );

            // Restore code blocks
            clean = clean.replace(/___MWASSISTANT_CODE_BLOCK_(\d+)___/g, (_, idx) => {
                return codeBlocks[parseInt(idx, 10)];
            });

            return clean;
        }

        escapeHtml(text) {
            return $('<div>').text(text).html();
        }

        /**
         * Returns true only for HTTP(S), protocol-relative, root-relative,
         * or in-page anchor URLs. Rejects javascript:, data:, vbscript:, etc.
         */
        isSafeUrl(url) {
            const trimmed = String(url).trim();
            return /^(https?:\/\/|\/\/|\/|#)/i.test(trimmed);
        }

        /* ------------------------------------------------------------------
         * Message Rendering
         * ------------------------------------------------------------------ */

        appendMessage(role, content) {
            const $log = this.$log;
            
            // Remove welcome message if present
            $log.find('.mwassistant-welcome').remove();
            
            const cls = role === 'user' ? 'mwassistant-msg-user' : 'mwassistant-msg-assistant';

            const $msg = $('<div>')
                .addClass(`mwassistant-msg ${cls}`)
                .html(this.parseMarkdown(content));

            $log.append($msg);

            // Auto-scroll to bottom
            $log.scrollTop($log.prop('scrollHeight'));
        }

        /**
         * Build a "human-friendly" header + query string for a tool call.
         */
        formatToolHeader(toolName, args) {
            switch (toolName) {
                case 'mw_run_smw_ask':
                    return { headerTitle: 'SMW Query', displayQuery: args.ask || 'No query' };
                case 'mw_get_page':
                    return { headerTitle: 'Read Page', displayQuery: args.title || 'Unknown Page' };
                case 'mw_page_info':
                    return { headerTitle: 'Page Info', displayQuery: args.title || 'Unknown Page' };
                case 'mw_search_pages':
                    return { headerTitle: 'Keyword Search', displayQuery: args.query || '' };
                case 'mw_vector_search':
                    return { headerTitle: 'Vector Search', displayQuery: args.query || '' };
                case 'mw_get_categories':
                case 'mw_get_properties': {
                    const label = toolName === 'mw_get_categories' ? 'Category Check' : 'Property Check';
                    if (args.names) return { headerTitle: label, displayQuery: 'Checking: ' + args.names.join(', ') };
                    if (args.prefix) return { headerTitle: label, displayQuery: 'Search: ' + args.prefix };
                    return { headerTitle: label, displayQuery: 'List all' };
                }
                case 'mw_list_pages':
                    return { headerTitle: 'List Pages', displayQuery: args.namespace || args.prefix || 'all' };
                case 'mw_get_category_members':
                    return { headerTitle: 'Category Members', displayQuery: args.category || '' };
                default:
                    return { headerTitle: toolName, displayQuery: JSON.stringify(args) };
            }
        }

        /**
         * Render the result body + preview for a tool call. Returns
         * { displayResult: <html>, resultPreview: <text> }.
         */
        formatToolResult(toolName, result) {
            let displayResult = '';
            let resultPreview = '';

            if (result && result.error) {
                displayResult = `<span class="mwassistant-error">${this.escapeHtml(result.error)}</span>`;
                resultPreview = `Error: ${result.error}`;
            } else if (toolName === 'mw_run_smw_ask') {
                if (result && result['mwassistant-smw'] && result['mwassistant-smw'].result) {
                    displayResult = result['mwassistant-smw'].result;
                    resultPreview = 'SMW Result';
                } else {
                    displayResult = `<pre>${this.escapeHtml(JSON.stringify(result, null, 2))}</pre>`;
                    resultPreview = 'JSON Result';
                }
            } else if (Array.isArray(result)) {
                if (result.length === 0) {
                    displayResult = '<em>No matches found.</em>';
                    resultPreview = 'No matches';
                } else if (typeof result[0] === 'string') {
                    displayResult = `<ul class="mwassistant-tool-list">${result.map(x => `<li>${this.escapeHtml(x)}</li>`).join('')}</ul>`;
                    resultPreview = result.join(', ');
                } else if (result[0] && result[0].title && typeof result[0].score === 'number') {
                    displayResult = `<ul class="mwassistant-tool-list">${
                        result.map(x => `<li><b>[[${this.escapeHtml(x.title)}]]</b> (Score: ${x.score.toFixed(2)})</li>`).join('')
                    }</ul>`;
                    resultPreview = `${result.length} results found`;
                } else {
                    displayResult = `<pre>${this.escapeHtml(JSON.stringify(result, null, 2))}</pre>`;
                    resultPreview = 'Array Result';
                }
            } else if (typeof result === 'string') {
                const maxLen = 500;
                resultPreview = result;
                if (result.length > maxLen) {
                    displayResult = `<div class="mwassistant-collapsed-content">${
                        this.escapeHtml(result.substring(0, maxLen))
                    }...<br><em>(${result.length} chars total)</em></div>`;
                } else {
                    displayResult = this.escapeHtml(result);
                }
            } else {
                displayResult = `<pre>${this.escapeHtml(JSON.stringify(result, null, 2))}</pre>`;
                resultPreview = 'Output Object';
            }

            if (resultPreview.length > 50) resultPreview = resultPreview.substring(0, 50) + '...';
            return { displayResult, resultPreview };
        }

        /**
         * Convert raw tool args (string-encoded JSON or object) into a plain object.
         */
        normalizeToolArgs(rawArgs) {
            try {
                return typeof rawArgs === 'string' ? JSON.parse(rawArgs) : (rawArgs || {});
            } catch (e) {
                return { raw: rawArgs };
            }
        }

        /**
         * Render a tool block in "running" state — shown immediately when a
         * tool_start event arrives so the user sees what's happening.
         * Returns the jQuery node so it can later be upgraded with the result.
         */
        appendToolPending(toolName, rawArgs) {
            const $log = this.$log;
            $log.find('.mwassistant-welcome').remove();

            const args = this.normalizeToolArgs(rawArgs);
            const { headerTitle, displayQuery } = this.formatToolHeader(toolName, args);
            let queryPreview = typeof displayQuery === 'string' ? displayQuery : JSON.stringify(displayQuery);
            if (queryPreview.length > 50) queryPreview = queryPreview.substring(0, 50) + '...';

            const $msg = $('<div>').addClass('mwassistant-msg mwassistant-msg-tool');
            $msg.html(`
                <details class="mwassistant-tool-details mwassistant-tool-pending">
                    <summary class="mwassistant-tool-summary">
                        <span class="mwassistant-tool-name">${this.escapeHtml(headerTitle)}</span>
                        <span class="mwassistant-tool-preview">
                            <span class="mwassistant-tool-preview-query">${this.escapeHtml(queryPreview)}</span>
                            <span class="mwassistant-tool-spinner" aria-hidden="true"></span>
                            <span class="mwassistant-tool-status">Running...</span>
                        </span>
                    </summary>
                    <div class="mwassistant-tool-expanded">
                        <div class="mwassistant-tool-query"><code>${this.escapeHtml(String(displayQuery))}</code></div>
                        <div class="mwassistant-tool-result">
                            <div class="mwassistant-tool-result-header">Result:</div>
                            <div class="mwassistant-tool-result-content"><em>Waiting for response…</em></div>
                        </div>
                    </div>
                </details>
            `);

            $log.append($msg);
            $log.scrollTop($log.prop('scrollHeight'));
            return $msg;
        }

        /**
         * Upgrade a "running" tool block in place once the result arrives.
         */
        finalizeToolMessage($msg, toolName, result, opts) {
            opts = opts || {};
            const { displayResult, resultPreview } = this.formatToolResult(toolName, result);
            const elapsedMs = typeof opts.elapsedMs === 'number' ? opts.elapsedMs : null;
            const ok = opts.ok !== false;

            $msg.find('.mwassistant-tool-details')
                .removeClass('mwassistant-tool-pending')
                .toggleClass('mwassistant-tool-error', !ok);
            $msg.find('.mwassistant-tool-spinner').remove();
            $msg.find('.mwassistant-tool-status').remove();

            const elapsedTag = elapsedMs !== null
                ? ` <span class="mwassistant-tool-elapsed">${elapsedMs} ms</span>`
                : '';
            $msg.find('.mwassistant-tool-preview').html(
                `<span class="mwassistant-tool-preview-query">${
                    this.escapeHtml($msg.find('.mwassistant-tool-preview-query').text())
                }</span> &rarr; <span class="mwassistant-tool-preview-result">${
                    this.escapeHtml(resultPreview)
                }</span>${elapsedTag}`
            );
            $msg.find('.mwassistant-tool-result-content').html(displayResult);

            const $log = this.$log;
            $log.scrollTop($log.prop('scrollHeight'));
        }

        /**
         * Render a tool block in one shot (no pending → final transition).
         * Used by the buffered (non-streaming) path and as a fallback when a
         * tool_result arrives without a matching tool_start.
         */
        appendToolMessage(toolName, rawArgs, result) {
            const $msg = this.appendToolPending(toolName, rawArgs);
            this.finalizeToolMessage($msg, toolName, result, { ok: !(result && result.error) });
        }

        /**
         * Render a "thinking..." indicator while waiting for the next event.
         * Returns a function that removes the indicator.
         */
        showThinkingIndicator(label) {
            const $log = this.$log;
            $log.find('.mwassistant-welcome').remove();

            const $msg = $('<div>').addClass('mwassistant-msg mwassistant-msg-thinking');
            $msg.html(`
                <span class="mwassistant-thinking-dots" aria-hidden="true">
                    <span></span><span></span><span></span>
                </span>
                <span class="mwassistant-thinking-label">${this.escapeHtml(label || 'Thinking…')}</span>
            `);
            $log.append($msg);
            $log.scrollTop($log.prop('scrollHeight'));

            return () => $msg.remove();
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

            // New chat button
            $root.on('click', '#mwassistant-new-chat', () => this.startNewChat());

            // Session list click
            $root.on('click', '.mwassistant-session-item', (e) => {
                if ($(e.target).hasClass('mwassistant-session-delete')) return;
                const sessionId = $(e.currentTarget).data('session-id');
                if (sessionId) this.loadSession(sessionId);
            });

            // Session delete button
            $root.on('click', '.mwassistant-session-delete', (e) => {
                e.stopPropagation();
                const sessionId = $(e.currentTarget).data('session-id');
                if (sessionId) this.deleteSession(sessionId);
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

            const $btn = this.$container.find('#mwassistant-chat-send');
            $btn.prop('disabled', true);

            try {
                if (typeof window.fetch === 'function' && window.ReadableStream) {
                    await this.sendMessageStreaming(text);
                } else {
                    await this.sendMessageBuffered(text);
                }
            } catch (err) {
                console.error('MWAssistant API error:', err);
                this.appendMessage('assistant', 'Error: failed to reach server.');
            } finally {
                $btn.prop('disabled', false);
            }
        }

        /**
         * Streaming path: POST to Special:MWAssistantStream and parse SSE
         * frames so we can render each tool step as it happens.
         */
        async sendMessageStreaming(text) {
            const messages = this.buildMessagesArray(text);
            const body = { messages, context: this.context };
            if (this.sessionId) body.session_id = this.sessionId;

            const url = mw.util.getUrl('Special:MWAssistantStream') +
                '?token=' + encodeURIComponent(mw.user.tokens.get('csrfToken'));

            const res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'text/event-stream',
                },
                body: JSON.stringify(body),
            });

            if (!res.ok || !res.body) {
                let detail = 'Failed to reach server.';
                try {
                    const errBody = await res.json();
                    if (errBody && errBody.message) detail = errBody.message;
                } catch (_) { /* ignore */ }
                this.appendMessage('assistant', 'Error: ' + detail);
                return;
            }

            this._activeToolNodes.clear();
            let dismissThinking = this.showThinkingIndicator('Thinking…');
            let sawFinalAssistant = false;
            let sawAnyEvent = false;

            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            const MAX_BUFFER_BYTES = 4 * 1024 * 1024;
            let buffer = '';

            try {
                while (true) {
                    const { value, done } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });

                    if (buffer.length > MAX_BUFFER_BYTES) {
                        console.warn('MWAssistant: SSE buffer exceeded ' + MAX_BUFFER_BYTES + ' bytes; aborting stream.');
                        await reader.cancel();
                        break;
                    }

                    let sepIndex;
                    while ((sepIndex = buffer.indexOf('\n\n')) !== -1) {
                        const frame = buffer.slice(0, sepIndex);
                        buffer = buffer.slice(sepIndex + 2);
                        const parsed = this.parseSseFrame(frame);
                        if (!parsed) continue;
                        sawAnyEvent = true;

                        // Heartbeat just confirms the pipe is alive — don't
                        // surface it, but it does count as "we got bytes".
                        if (parsed.event === 'heartbeat') continue;

                        // Hide the placeholder spinner the instant any signal arrives.
                        if (dismissThinking) {
                            dismissThinking();
                            dismissThinking = null;
                        }

                        if (this.handleStreamEvent(parsed.event, parsed.data)) {
                            sawFinalAssistant = true;
                        }

                        // After tool_result the LLM is thinking again until the next event.
                        if (parsed.event === 'tool_result' && !sawFinalAssistant) {
                            dismissThinking = this.showThinkingIndicator('Thinking…');
                        }
                    }
                }
            } finally {
                if (dismissThinking) dismissThinking();
                this._activeToolNodes.clear();
            }

            // If the stream closed without any events, the response body was
            // empty — almost always a reverse-proxy / output-buffering issue
            // between PHP and the browser. Show a generic message; the
            // diagnostic detail goes to console for the operator.
            if (!sawAnyEvent) {
                console.warn('MWAssistant: stream closed with no events. Likely server-side buffering (Apache mod_deflate / FastCGI flushpackets). See Special:MWAssistantStream proxy notes.');
                this.appendMessage('assistant', 'Error: the assistant connection closed before sending a response.');
            }
        }

        /**
         * Fallback path (older browsers without fetch streams): use the
         * legacy buffered API endpoint and render once everything finishes.
         */
        async sendMessageBuffered(text) {
            const payload = this.buildPayload(text);
            const data = await this.mwApi.post(payload);
            this.handleResponse(data);
        }

        /**
         * Parse a single SSE frame ("event: foo\ndata: {...}").
         */
        parseSseFrame(raw) {
            const lines = raw.split('\n');
            let event = 'message';
            const dataLines = [];
            for (const line of lines) {
                if (line.startsWith('event:')) {
                    event = line.slice(6).trim();
                } else if (line.startsWith('data:')) {
                    dataLines.push(line.slice(5).trim());
                }
            }
            if (dataLines.length === 0) return null;
            const dataStr = dataLines.join('\n');
            try {
                return { event, data: JSON.parse(dataStr) };
            } catch (e) {
                console.warn('Failed to parse SSE frame:', dataStr);
                return null;
            }
        }

        /**
         * Dispatch a parsed SSE event into UI updates. Returns true when the
         * user-visible answer was rendered (so the caller stops the
         * "thinking" indicator).
         */
        handleStreamEvent(event, data) {
            switch (event) {
                case 'session': {
                    if (data.session_id && data.session_id !== this.sessionId) {
                        const fresh = !this.sessionId;
                        this.sessionId = data.session_id;
                        if (fresh) this.loadSessions();
                    }
                    return false;
                }
                case 'tool_start': {
                    const $node = this.appendToolPending(data.name, data.args || {});
                    if (data.call_id) this._activeToolNodes.set(data.call_id, $node);
                    return false;
                }
                case 'tool_result': {
                    const $node = data.call_id
                        ? this._activeToolNodes.get(data.call_id)
                        : null;
                    if ($node) {
                        this._activeToolNodes.delete(data.call_id);
                        this.finalizeToolMessage($node, data.name, data.result_preview, {
                            ok: data.ok !== false,
                            elapsedMs: data.elapsed_ms,
                        });
                    } else {
                        // No matching start — render in one shot as a fallback.
                        this.appendToolMessage(data.name, {}, data.result_preview);
                    }
                    return false;
                }
                case 'assistant_message': {
                    if (!data.content) return false;
                    this.appendMessage('assistant', data.content);
                    return !!data.is_final;
                }
                case 'error': {
                    const msg = (data && data.message) || 'The assistant ran into an error.';
                    this.appendMessage('assistant', 'Error: ' + msg);
                    return true;
                }
                case 'done': {
                    // Refresh sidebar so the new title appears for first turns.
                    if (data.session_id && !this.hideSessions) {
                        this.loadSessions();
                    }
                    return false;
                }
                default:
                    return false;
            }
        }

        /**
         * Build just the messages array (for streaming endpoint payload).
         */
        buildMessagesArray(userText) {
            const messages = [];
            const extra = this.getExtraContext();
            if (extra) {
                messages.push({ role: 'system', content: 'Context:\n' + extra });
            }
            messages.push({ role: 'user', content: userText });
            return messages;
        }

        /* ------------------------------------------------------------------
         * Request Payload Assembly
         * ------------------------------------------------------------------ */

        buildPayload(userText) {
            const messages = [];

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

            const payload = {
                action: 'mwassistant-chat',
                format: 'json',
                messages: JSON.stringify(messages),
                context: this.context,
                token: mw.user.tokens.get('csrfToken')
            };

            if (this.sessionId) {
                payload.session_id = this.sessionId;
            }

            return payload;
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

            // Update session ID from response
            if (result.session_id && !this.sessionId) {
                this.sessionId = result.session_id;
                // Reload session list to include new session
                this.loadSessions();
            }

            // Show tool usage if present
            if (result.used_tools && result.used_tools.length) {
                result.used_tools.forEach(tool => {
                    this.appendToolMessage(tool.name, tool.args, tool.result);
                });
            }

            // Show assistant message
            if (result.messages?.length) {
                const last = result.messages[result.messages.length - 1];
                this.appendMessage(last.role, last.content);
                
                // Update title if this was the first message
                if (result.messages.length <= 2) {
                    // Try to get title from session
                    const session = this.sessions.find(s => s.session_id === this.sessionId);
                    if (session?.title) {
                        this.$container.find('#mwassistant-chat-title').text(session.title);
                    }
                }
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
