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
         */
        constructor(config) {
            this.$container = config.$container;
            this.context = config.context || 'chat';
            this.getExtraContext = config.getExtraContext || (() => null);

            this.sessionId = null;  // Will be set when session is loaded/created
            this.sessions = [];
            this.mwApi = new mw.Api();

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
        }

        /* ------------------------------------------------------------------
         * Session List Management
         * ------------------------------------------------------------------ */

        async loadSessions() {
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

            const items = this.sessions.map(s => `
                <div class="mwassistant-session-item ${s.session_id === this.sessionId ? 'active' : ''}" 
                     data-session-id="${s.session_id}">
                    <div class="mwassistant-session-info">
                        <span class="mwassistant-session-title">${this.escapeHtml(s.title || 'Untitled')}</span>
                        <span class="mwassistant-session-date">${formatDate(s.updated_at)}</span>
                    </div>
                    <button class="mwassistant-session-delete" data-session-id="${s.session_id}" title="Delete">×</button>
                </div>
            `).join('');

            $list.html(items);
        }

        showSessionError(message) {
            const $list = this.$container.find('#mwassistant-session-list');
            $list.html(`<div class="mwassistant-error">${this.escapeHtml(message)}</div>`);
        }

        async loadSession(sessionId) {
            const $log = this.$container.find('#mwassistant-chat-log');
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
            this.sessionId = null;
            this.$container.find('#mwassistant-chat-title').text('New Chat');
            this.$container.find('#mwassistant-chat-log').html(`
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

        escapeHtml(text) {
            return $('<div>').text(text).html();
        }

        /* ------------------------------------------------------------------
         * Message Rendering
         * ------------------------------------------------------------------ */

        appendMessage(role, content) {
            const $log = this.$container.find('#mwassistant-chat-log');
            
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

        appendToolMessage(toolName, rawArgs, result) {
            const $log = this.$container.find('#mwassistant-chat-log');
            let args = {};
            try {
                args = typeof rawArgs === 'string' ? JSON.parse(rawArgs) : rawArgs;
            } catch (e) { args = { raw: rawArgs }; }

            let displayQuery = "";
            let displayResult = "";
            let headerTitle = "Tool Execution";

            // Format Header & Query based on tool type
            switch (toolName) {
                case 'mw_run_smw_ask':
                    headerTitle = "SMW Query";
                    displayQuery = args.ask || "No query";
                    break;
                case 'mw_get_page':
                    headerTitle = "Read Page";
                    displayQuery = args.title || "Unknown Page";
                    break;
                case 'mw_get_categories':
                    headerTitle = "Category Check";
                    if (args.names) displayQuery = "Checking: " + args.names.join(", ");
                    else if (args.prefix) displayQuery = "Search: " + args.prefix;
                    else displayQuery = "List all";
                    break;
                case 'mw_get_properties':
                    headerTitle = "Property Check";
                    if (args.names) displayQuery = "Checking: " + args.names.join(", ");
                    else if (args.prefix) displayQuery = "Search: " + args.prefix;
                    else displayQuery = "List all";
                    break;
                case 'mw_vector_search':
                    headerTitle = "Vector Search";
                    displayQuery = args.query || "";
                    break;
                default:
                    headerTitle = toolName;
                    displayQuery = JSON.stringify(args);
            }

            // Generate Preview Text (Query)
            let queryPreview = typeof displayQuery === 'string' ? displayQuery : JSON.stringify(displayQuery);
            if (queryPreview.length > 50) queryPreview = queryPreview.substring(0, 50) + "...";

            // Format Result & Preview
            let resultPreview = "";

            if (result && result.error) {
                displayResult = `<span class="mwassistant-error">${result.error}</span>`;
                resultPreview = `Error: ${result.error}`;
            } else if (toolName === 'mw_run_smw_ask') {
                if (result['mwassistant-smw']?.result) {
                    displayResult = result['mwassistant-smw'].result;
                    resultPreview = "SMW Result";
                } else {
                    displayResult = JSON.stringify(result, null, 2);
                    resultPreview = "JSON Result";
                }
            } else if (Array.isArray(result)) {
                if (result.length === 0) {
                    displayResult = "<em>No matches found.</em>";
                    resultPreview = "No matches";
                } else if (typeof result[0] === 'string') {
                    displayResult = `<ul class="mwassistant-tool-list">${result.map(x => `<li>${x}</li>`).join('')}</ul>`;
                    resultPreview = result.join(", ");
                } else if (result[0]?.title && result[0]?.score) {
                    displayResult = `<ul class="mwassistant-tool-list">
                        ${result.map(x => `<li><b>[[${x.title}]]</b> (Score: ${x.score.toFixed(2)})</li>`).join('')}
                    </ul>`;
                    resultPreview = `${result.length} results found`;
                } else {
                    displayResult = `<pre>${JSON.stringify(result, null, 2)}</pre>`;
                    resultPreview = "Array Result";
                }
            } else if (typeof result === 'string') {
                const maxLen = 500;
                resultPreview = result;
                if (result.length > maxLen) {
                    displayResult = `<div class="mwassistant-collapsed-content">
                        ${$('<div>').text(result.substring(0, maxLen)).html()}...
                        <br><em>(${result.length} chars total)</em>
                    </div>`;
                } else {
                    displayResult = $('<div>').text(result).html();
                }
            } else {
                displayResult = `<pre>${JSON.stringify(result, null, 2)}</pre>`;
                resultPreview = "Output Object";
            }

            // Truncate result preview
            if (resultPreview.length > 50) resultPreview = resultPreview.substring(0, 50) + "...";

            const $msg = $('<div>').addClass('mwassistant-msg mwassistant-msg-tool');

            const html = `
                <details class="mwassistant-tool-details">
                    <summary class="mwassistant-tool-summary">
                        <span class="mwassistant-tool-name">${headerTitle}</span>
                        <span class="mwassistant-tool-preview"><span class="mwassistant-tool-preview-query">${$('<div>').text(queryPreview).html()}</span> &rarr; <span class="mwassistant-tool-preview-result">${$('<div>').text(resultPreview).html()}</span></span>
                    </summary>
                    <div class="mwassistant-tool-expanded">
                        <div class="mwassistant-tool-query"><code>${$('<div>').text(displayQuery).html()}</code></div>
                        <div class="mwassistant-tool-result">
                            <div class="mwassistant-tool-result-header">Result:</div>
                            <div class="mwassistant-tool-result-content">${displayResult}</div>
                        </div>
                    </div>
                </details>
            `;

            $msg.html(html);
            $log.append($msg);
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
