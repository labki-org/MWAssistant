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

            // In-flight request tracking (see sendMessage()).
            this._inFlight = false;
            this._requestSeq = 0;
            this._activeRequestId = null;
            this._abortController = null;
            this._loadSeq = 0;

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
                            <button type="button" class="mwassistant-new-chat" id="mwassistant-new-chat">
                                <span class="mwassistant-icon" aria-hidden="true">+</span> ${this.escapeHtml(mw.msg('mwassistant-chat-new'))}
                            </button>
                        </div>
                        <div class="mwassistant-session-list" id="mwassistant-session-list"
                             role="navigation"
                             aria-label="${this.escapeHtml(mw.msg('mwassistant-chat-sessions-label'))}">
                            <div class="mwassistant-loading">${this.escapeHtml(mw.msg('mwassistant-chat-loading-sessions'))}</div>
                        </div>
                    </div>
                    <div class="mwassistant-chat">
                        <div class="mwassistant-chat-header">
                            <h2 id="mwassistant-chat-title">${this.escapeHtml(mw.msg('mwassistant-chat-new'))}</h2>
                        </div>

                        <div class="mwassistant-chat-log" id="mwassistant-chat-log"
                             role="log" aria-live="polite" aria-relevant="additions"
                             aria-label="${this.escapeHtml(mw.msg('mwassistant-chat-log-label'))}">
                            ${this.welcomeHtml()}
                        </div>

                        <div class="mwassistant-chat-input">
                            <label for="mwassistant-chat-input-text" class="mwassistant-visually-hidden">${
                                this.escapeHtml(mw.msg('mwassistant-chat-input-label'))
                            }</label>
                            <textarea
                                id="mwassistant-chat-input-text"
                                rows="3"
                                placeholder="${this.escapeHtml(mw.msg('mwassistant-chat-input-placeholder'))}"
                            ></textarea>
                            <button type="button" id="mwassistant-chat-send">${this.escapeHtml(mw.msg('mwassistant-chat-send'))}</button>
                        </div>
                    </div>
                </div>
            `;

            this.$container.html(html);
            this.$log = this.$container.find('#mwassistant-chat-log');
        }

        welcomeHtml() {
            return '<div class="mwassistant-welcome"><p>' +
                this.escapeHtml(mw.msg('mwassistant-chat-welcome')) +
                '</p></div>';
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
                    this.showSessionError((result && result.message) || mw.msg('mwassistant-chat-sessions-error'));
                }
            } catch (err) {
                console.error('Failed to load sessions:', err);
                this.showSessionError(mw.msg('mwassistant-chat-sessions-error'));
            }
        }

        renderSessionList() {
            const $list = this.$container.find('#mwassistant-session-list');
            
            if (this.sessions.length === 0) {
                $list.html(`<div class="mwassistant-empty">${this.escapeHtml(mw.msg('mwassistant-chat-sessions-empty'))}</div>`);
                return;
            }

            const deleteLabel = this.escapeHtml(mw.msg('mwassistant-chat-session-delete'));
            const items = this.sessions.map(s => {
                const safeId = this.escapeHtml(s.session_id || '');
                const safeTitle = this.escapeHtml(s.title || mw.msg('mwassistant-chat-session-untitled'));
                const safeDate = this.escapeHtml(formatDate(s.updated_at));
                const isActive = s.session_id === this.sessionId;
                return `
                <div class="mwassistant-session-item ${isActive ? 'active' : ''}"
                     data-session-id="${safeId}">
                    <button type="button" class="mwassistant-session-info"
                        ${isActive ? 'aria-current="true"' : ''}>
                        <span class="mwassistant-session-title">${safeTitle}</span>
                        <span class="mwassistant-session-date">${safeDate}</span>
                    </button>
                    <button type="button" class="mwassistant-session-delete" data-session-id="${safeId}" title="${deleteLabel}" aria-label="${deleteLabel}">×</button>
                </div>`;
            }).join('');

            $list.html(items);
        }

        showSessionError(message) {
            const $list = this.$container.find('#mwassistant-session-list');
            $list.html(`<div class="mwassistant-error">${this.escapeHtml(message)}</div>`);
        }

        async loadSession(sessionId) {
            this.abortActiveRequest();
            const loadId = ++this._loadSeq;
            const $log = this.$log;
            $log.html(`<div class="mwassistant-loading">${this.escapeHtml(mw.msg('mwassistant-chat-loading-conversation'))}</div>`);

            try {
                const data = await this.mwApi.post({
                    action: 'mwassistant-sessions',
                    command: 'get',
                    session_id: sessionId,
                    token: mw.user.tokens.get('csrfToken')
                });

                // A newer load / new chat superseded this one.
                if (loadId !== this._loadSeq) return;

                const result = data['mwassistant-sessions'];
                if (result && !result.error && result.messages) {
                    this.sessionId = sessionId;
                    this.$container.find('#mwassistant-chat-title').text(result.title || mw.msg('mwassistant-chat-session-untitled'));
                    
                    $log.empty();
                    result.messages.forEach(msg => {
                        this.appendMessage(msg.role, msg.content);
                    });

                    this.renderSessionList();  // Update active state
                } else {
                    $log.html(`<div class="mwassistant-error">${this.escapeHtml(mw.msg('mwassistant-chat-conversation-error'))}</div>`);
                }
            } catch (err) {
                if (loadId !== this._loadSeq) return;
                console.error('Failed to load session:', err);
                $log.html(`<div class="mwassistant-error">${this.escapeHtml(mw.msg('mwassistant-chat-conversation-error'))}</div>`);
            }
        }

        async deleteSession(sessionId) {
            if (!confirm(mw.msg('mwassistant-chat-session-delete-confirm'))) return;

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
                alert(mw.msg('mwassistant-chat-session-delete-error'));
            }
        }

        startNewChat() {
            this.abortActiveRequest();
            this._loadSeq++;
            this.sessionId = null;
            this.$container.find('#mwassistant-chat-title').text(mw.msg('mwassistant-chat-new'));
            this.$log.html(this.welcomeHtml());
            this.renderSessionList();  // Clear active state
        }

        /* ------------------------------------------------------------------
         * Markdown Parser (Safe)
         *
         * Two-pass: block-level structure (paragraphs, headings, lists,
         * blockquotes, fenced code) is built from the raw line stream;
         * inline structure (code, bold, italic, links, wikilinks) runs on
         * each text segment after HTML-escaping. Doing block detection on
         * raw text means we never have to un-escape anything to spot a
         * `## heading` or a `1.` list marker, and HTML in user/LLM text is
         * still escaped before being inserted into the DOM.
         * ------------------------------------------------------------------ */

        parseMarkdown(raw) {
            if (!raw) return "";

            // Pull fenced code blocks out first so their contents never get
            // mistaken for headings / lists / paragraphs. We store the raw
            // code and escape it at render time.
            // NUL delimits the code-block placeholders below; strip any
            // from the input so text cannot forge one.
            const codeBlocks = [];
            const stashed = String(raw).replace(/\u0000/g, '').replace(/```([\s\S]*?)```/g, (_, code) => {
                const index = codeBlocks.length;
                codeBlocks.push(code.replace(/^\n+|\n+$/g, ''));
                return `\n\u0000CODE_BLOCK_${index}\u0000\n`;
            });

            const lines = stashed.split('\n');
            const out = [];
            let i = 0;

            const isCodePlaceholder = (s) => /^\u0000CODE_BLOCK_(\d+)\u0000$/.test(s.trim());
            const isHeading = (s) => /^#{1,6}\s+\S/.test(s);
            const isOrdered = (s) => /^\s*\d+\.\s+\S/.test(s);
            const isBullet = (s) => /^\s*[-*+]\s+\S/.test(s);
            const isQuote = (s) => /^>\s?/.test(s);

            while (i < lines.length) {
                const line = lines[i];
                const trimmed = line.trim();

                // Blank line – just a separator between blocks.
                if (!trimmed) { i++; continue; }

                // Fenced code block.
                const cm = trimmed.match(/^\u0000CODE_BLOCK_(\d+)\u0000$/);
                if (cm) {
                    const code = this.escapeHtml(codeBlocks[parseInt(cm[1], 10)]);
                    out.push(
                        '<div class="mwassistant-code-wrapper">' +
                            `<button type="button" class="mwassistant-copy-btn" title="${this.escapeHtml(mw.msg('mwassistant-chat-copy-code'))}">${this.escapeHtml(mw.msg('mwassistant-chat-copy'))}</button>` +
                            `<pre class="mwassistant-code-block"><code>${code}</code></pre>` +
                        '</div>'
                    );
                    i++;
                    continue;
                }

                // ATX heading.
                const hm = trimmed.match(/^(#{1,6})\s+(.+?)\s*#*\s*$/);
                if (hm) {
                    const level = hm[1].length;
                    out.push(`<h${level} class="mwassistant-md-h${level}">${this.renderInline(hm[2])}</h${level}>`);
                    i++;
                    continue;
                }

                // Ordered list — consume consecutive `N.` lines. Allow a
                // single blank line between items (CommonMark "loose list").
                if (isOrdered(line)) {
                    const items = [];
                    while (i < lines.length) {
                        if (isOrdered(lines[i])) {
                            const text = lines[i].replace(/^\s*\d+\.\s+/, '');
                            items.push(`<li>${this.renderInline(text)}</li>`);
                            i++;
                        } else if (lines[i].trim() === '' && isOrdered(lines[i + 1] || '')) {
                            i++;
                        } else {
                            break;
                        }
                    }
                    out.push(`<ol class="mwassistant-md-list">${items.join('')}</ol>`);
                    continue;
                }

                // Unordered list — same loose-list handling.
                if (isBullet(line)) {
                    const items = [];
                    while (i < lines.length) {
                        if (isBullet(lines[i])) {
                            const text = lines[i].replace(/^\s*[-*+]\s+/, '');
                            items.push(`<li>${this.renderInline(text)}</li>`);
                            i++;
                        } else if (lines[i].trim() === '' && isBullet(lines[i + 1] || '')) {
                            i++;
                        } else {
                            break;
                        }
                    }
                    out.push(`<ul class="mwassistant-md-list">${items.join('')}</ul>`);
                    continue;
                }

                // Blockquote — accumulate consecutive `> ` lines.
                if (isQuote(line)) {
                    const buf = [];
                    while (i < lines.length && isQuote(lines[i])) {
                        buf.push(lines[i].replace(/^>\s?/, ''));
                        i++;
                    }
                    out.push(`<blockquote class="mwassistant-md-blockquote">${this.renderInline(buf.join(' '))}</blockquote>`);
                    continue;
                }

                // Paragraph: collect consecutive non-special lines. Single
                // newlines inside a paragraph become a single space; blank
                // lines start a new paragraph (CommonMark behavior).
                const buf = [];
                while (i < lines.length) {
                    const l = lines[i];
                    const t = l.trim();
                    if (!t) break;
                    if (isCodePlaceholder(t) || isHeading(l) || isOrdered(l) || isBullet(l) || isQuote(l)) break;
                    buf.push(t);
                    i++;
                }
                if (buf.length) {
                    out.push(`<p>${this.renderInline(buf.join(' '))}</p>`);
                }
            }

            return out.join('');
        }

        /**
         * Run inline markdown transforms on a raw text segment.
         *
         * Constructs that produce markup with attributes (inline code,
         * wikilinks, markdown links, bare URLs) are matched against the
         * *raw* text and their HTML is built with every piece escaped via
         * mw.html.escape (which, unlike jQuery's .text().html(), also
         * escapes quotes). The finished HTML is stashed behind NUL-delimited
         * placeholders so the remaining text can be escaped and given
         * bold/italic treatment without touching it. Model output is
         * untrusted (it can echo prompt-injected wiki content), so nothing
         * raw ever reaches an attribute value.
         */
        renderInline(raw) {
            const stash = [];
            const hold = (html) => {
                stash.push(html);
                return '\u0000' + (stash.length - 1) + '\u0000';
            };

            // NUL is our placeholder delimiter; it never belongs in chat text.
            let text = String(raw == null ? '' : raw).replace(/\u0000/g, '');

            // Inline code – contents are shown verbatim.
            text = text.replace(/`([^`]+)`/g, (_, code) =>
                hold(`<code class="mwassistant-inline-code">${this.escapeHtml(code)}</code>`)
            );

            // Wiki links. A leading colon (`[[:Category:X]]`) is a wikitext
            // convention with no meaning in chat output, so strip it before
            // resolving the URL.
            text = text.replace(
                /\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/g,
                (_, page, label) => {
                    const target = page.trim().replace(/^:/, '');
                    let url;
                    try {
                        url = mw.util.getUrl(target);
                    } catch (e) {
                        return hold(this.escapeHtml(label || target));
                    }
                    return hold(
                        `<a href="${this.escapeHtml(url)}" title="${this.escapeHtml(target)}">` +
                        this.renderEmphasis(this.escapeHtml(label || target)) +
                        '</a>'
                    );
                }
            );

            // Markdown links — only allow safe URL schemes to prevent
            // javascript:/data: injection through model output.
            text = text.replace(
                /\[([^\]]+)\]\(([^)\s]+)\)/g,
                (match, label, url) => {
                    if (!this.isSafeUrl(url)) {
                        return match;
                    }
                    return hold(
                        `<a href="${this.escapeHtml(url)}" target="_blank" rel="noopener noreferrer">` +
                        this.renderEmphasis(this.escapeHtml(label)) +
                        '</a>'
                    );
                }
            );

            // Bare URLs – the LLM frequently emits plain http(s) URLs and
            // they should be clickable too.
            text = text.replace(
                /(^|[\s(])(https?:\/\/[^\s<>"')\u0000]+[^\s<>"').,;:!?\u0000])/g,
                (_, lead, url) => lead + hold(
                    `<a href="${this.escapeHtml(url)}" target="_blank" rel="noopener noreferrer">` +
                    this.escapeHtml(url) +
                    '</a>'
                )
            );

            // Everything left is plain text: escape it, apply emphasis, then
            // swap the stashed markup back in.
            const html = this.renderEmphasis(this.escapeHtml(text));
            return html.replace(/\u0000(\d+)\u0000/g, (_, i) => stash[parseInt(i, 10)]);
        }

        /**
         * Bold / italic on already-escaped text. The patterns only ever wrap
         * existing (escaped) content in <b>/<i>, so they cannot introduce
         * attributes or unescaped markup.
         */
        renderEmphasis(escaped) {
            let clean = escaped;

            // Bold (** ... **) – run before italic so it wins the `*` tokens.
            clean = clean.replace(/\*\*([^*]+?)\*\*/g, '<b>$1</b>');

            // Italic (* ... * or _ ... _) – avoid eating `**` leftovers and
            // intra-word underscores like `foo_bar_baz`.
            clean = clean.replace(/(^|[^*])\*([^*\s][^*]*?)\*(?!\*)/g, '$1<i>$2</i>');
            clean = clean.replace(/(^|[\s(])_([^_\s][^_]*?)_(?![A-Za-z0-9])/g, '$1<i>$2</i>');

            return clean;
        }

        /**
         * Escape text for use in HTML element content *and* attribute values
         * (mw.html.escape escapes both quote characters).
         */
        escapeHtml(text) {
            return mw.html.escape(String(text == null ? '' : text));
        }

        /**
         * Returns true only for HTTP(S), protocol-relative, root-relative,
         * or in-page anchor URLs. Rejects javascript:, data:, vbscript:, etc.
         * Operates on the raw (unescaped) URL; control characters and
         * backslashes are rejected outright because browsers strip or
         * normalise them while parsing the scheme / authority.
         */
        isSafeUrl(url) {
            const value = String(url);
            if (/[\u0000- \u007f\\]/.test(value)) {
                return false;
            }
            return /^(https?:\/\/|\/\/[^/]|\/(?!\/)|#)/i.test(value);
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
         * Show an error as an assistant message.
         */
        showError(message) {
            this.appendMessage('assistant', mw.msg('mwassistant-chat-error', message));
        }

        /**
         * Build a "human-friendly" header + query string for a tool call.
         */
        formatToolHeader(toolName, args) {
            const unknownPage = mw.msg('mwassistant-chat-tool-unknown-page');
            switch (toolName) {
                case 'mw_run_smw_ask':
                    return { headerTitle: mw.msg('mwassistant-chat-tool-smw-ask'), displayQuery: args.ask || mw.msg('mwassistant-chat-tool-no-query') };
                case 'mw_get_page':
                    return { headerTitle: mw.msg('mwassistant-chat-tool-get-page'), displayQuery: args.title || unknownPage };
                case 'mw_page_info':
                    return { headerTitle: mw.msg('mwassistant-chat-tool-page-info'), displayQuery: args.title || unknownPage };
                case 'mw_search_pages':
                    return { headerTitle: mw.msg('mwassistant-chat-tool-search-pages'), displayQuery: args.query || '' };
                case 'mw_find_pages_by_title':
                    return {
                        headerTitle: mw.msg('mwassistant-chat-tool-find-pages-by-title'),
                        displayQuery: (args.prefix || '') + (args.namespace ? ` (ns=${args.namespace})` : ''),
                    };
                case 'mw_vector_search':
                    return { headerTitle: mw.msg('mwassistant-chat-tool-vector-search'), displayQuery: args.query || '' };
                case 'mw_get_categories':
                case 'mw_get_properties': {
                    const label = toolName === 'mw_get_categories'
                        ? mw.msg('mwassistant-chat-tool-get-categories')
                        : mw.msg('mwassistant-chat-tool-get-properties');
                    if (Array.isArray(args.names)) return { headerTitle: label, displayQuery: mw.msg('mwassistant-chat-tool-checking', args.names.join(', ')) };
                    if (args.prefix) return { headerTitle: label, displayQuery: mw.msg('mwassistant-chat-tool-search-prefix', args.prefix) };
                    return { headerTitle: label, displayQuery: mw.msg('mwassistant-chat-tool-list-all') };
                }
                case 'mw_list_pages':
                    return { headerTitle: mw.msg('mwassistant-chat-tool-list-pages'), displayQuery: args.namespace || args.prefix || mw.msg('mwassistant-chat-tool-all') };
                case 'mw_get_category_members':
                    return { headerTitle: mw.msg('mwassistant-chat-tool-category-members'), displayQuery: args.category || '' };
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
                resultPreview = mw.msg('mwassistant-chat-error', result.error);
            } else if (toolName === 'mw_run_smw_ask') {
                const smw = result && result['mwassistant-smw'];
                if (smw && (Array.isArray(smw.rows) || smw.result)) {
                    displayResult = this.renderSmwResult(smw);
                    resultPreview = mw.msg('mwassistant-chat-tool-result-smw');
                } else {
                    displayResult = `<pre>${this.escapeHtml(JSON.stringify(result, null, 2))}</pre>`;
                    resultPreview = mw.msg('mwassistant-chat-tool-result-json');
                }
            } else if (this.isPaginatedEnvelope(result)) {
                ({ displayResult, resultPreview } =
                    this.formatPaginatedEnvelope(toolName, result));
            } else if (Array.isArray(result)) {
                if (result.length === 0) {
                    displayResult = this.noMatchesHtml();
                    resultPreview = mw.msg('mwassistant-chat-tool-no-matches-short');
                } else if (typeof result[0] === 'string') {
                    displayResult = this.renderStringList(result);
                    resultPreview = result.join(', ');
                } else if (result[0] && result[0].title && typeof result[0].score === 'number') {
                    displayResult = this.renderScoredList(result);
                    resultPreview = mw.msg('mwassistant-chat-tool-results-found', result.length);
                } else {
                    displayResult = `<pre>${this.escapeHtml(JSON.stringify(result, null, 2))}</pre>`;
                    resultPreview = mw.msg('mwassistant-chat-tool-result-array');
                }
            } else if (typeof result === 'string') {
                const maxLen = 500;
                resultPreview = result;
                if (result.length > maxLen) {
                    displayResult = `<div class="mwassistant-collapsed-content">${
                        this.escapeHtml(result.substring(0, maxLen))
                    }...<br><em>${this.escapeHtml(mw.msg('mwassistant-chat-tool-chars-total', result.length))}</em></div>`;
                } else {
                    displayResult = this.escapeHtml(result);
                }
            } else {
                displayResult = `<pre>${this.escapeHtml(JSON.stringify(result, null, 2))}</pre>`;
                resultPreview = mw.msg('mwassistant-chat-tool-result-object');
            }

            if (resultPreview.length > 50) resultPreview = resultPreview.substring(0, 50) + '...';
            return { displayResult, resultPreview };
        }

        /**
         * The MCP server wraps every listy tool's result in a
         * {results|matches|members, count, limit, truncated, note}
         * envelope so the LLM can detect when it hit the cap. Detect the
         * shape so we can show the truncation hint to the user too.
         */
        isPaginatedEnvelope(result) {
            return (
                result &&
                typeof result === 'object' &&
                !Array.isArray(result) &&
                typeof result.truncated === 'boolean' &&
                typeof result.limit === 'number'
            );
        }

        formatPaginatedEnvelope(toolName, result) {
            const listKey = ['results', 'matches', 'members'].find(
                (k) => Array.isArray(result[k])
            );
            const list = listKey ? result[listKey] : [];
            const suggestions = Array.isArray(result.suggestions) ? result.suggestions : [];

            let body;
            if (list.length === 0) {
                body = this.noMatchesHtml();
            } else if (typeof list[0] === 'string') {
                body = this.renderStringList(list);
            } else if (list[0] && typeof list[0].score === 'number' && list[0].title) {
                body = this.renderScoredList(list);
            } else if (list[0] && list[0].title) {
                body = this.renderTitleList(list);
            } else {
                body = `<pre>${this.escapeHtml(JSON.stringify(list, null, 2))}</pre>`;
            }

            const meta = result.truncated
                ? `<div class="mwassistant-tool-truncated">${this.escapeHtml(mw.msg('mwassistant-chat-tool-truncated', result.count, result.limit))}</div>`
                : `<div class="mwassistant-tool-meta">${this.escapeHtml(mw.msg('mwassistant-chat-tool-count', result.count, result.limit))}</div>`;

            let suggestionsHtml = '';
            if (suggestions.length) {
                suggestionsHtml =
                    `<div class="mwassistant-tool-suggestions"><b>${this.escapeHtml(mw.msg('mwassistant-chat-tool-suggestions'))}</b>` +
                    this.renderStringList(suggestions) +
                    '</div>';
            }

            const displayResult = meta + body + suggestionsHtml;
            const resultPreview = result.truncated
                ? mw.msg('mwassistant-chat-tool-preview-truncated', result.count, result.limit)
                : mw.msg('mwassistant-chat-tool-preview-count', result.count);
            return { displayResult, resultPreview };
        }

        /**
         * Render an SMW #ask result. Never inserts server-provided HTML:
         * structured `rows` become an escaped table/list, and the legacy
         * `result` string is shown as plain text.
         */
        renderSmwResult(smw) {
            if (Array.isArray(smw.rows)) {
                const rows = smw.rows;
                if (rows.length === 0) {
                    return this.noMatchesHtml();
                }
                const cell = (v) => this.escapeHtml(
                    v !== null && typeof v === 'object' ? JSON.stringify(v) : v
                );
                if (rows.every((r) => r !== null && typeof r === 'object' && !Array.isArray(r))) {
                    const cols = [];
                    rows.forEach((r) => Object.keys(r).forEach((k) => {
                        if (cols.indexOf(k) === -1) cols.push(k);
                    }));
                    const head = cols.map((c) => `<th>${this.escapeHtml(c)}</th>`).join('');
                    const body = rows.map((r) =>
                        `<tr>${cols.map((c) => `<td>${cell(r[c])}</td>`).join('')}</tr>`
                    ).join('');
                    return `<table class="mwassistant-smw-table"><thead><tr>${head}</tr></thead>` +
                        `<tbody>${body}</tbody></table>`;
                }
                return `<ul class="mwassistant-tool-list">${
                    rows.map((r) => `<li>${cell(r)}</li>`).join('')
                }</ul>`;
            }
            return `<div class="mwassistant-smw-text">${this.escapeHtml(smw.result)}</div>`;
        }

        noMatchesHtml() {
            return `<em>${this.escapeHtml(mw.msg('mwassistant-chat-tool-no-matches'))}</em>`;
        }

        renderStringList(items) {
            return `<ul class="mwassistant-tool-list">${
                items.map((x) => `<li>${this.escapeHtml(x)}</li>`).join('')
            }</ul>`;
        }

        renderScoredList(rows) {
            return `<ul class="mwassistant-tool-list">${
                rows.map((x) =>
                    `<li><b>[[${this.escapeHtml(x.title)}]]</b> ${this.escapeHtml(mw.msg('mwassistant-chat-tool-score', x.score.toFixed(2)))}</li>`
                ).join('')
            }</ul>`;
        }

        renderTitleList(rows) {
            return `<ul class="mwassistant-tool-list">${
                rows.map((x) => `<li><b>[[${this.escapeHtml(x.title)}]]</b></li>`).join('')
            }</ul>`;
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
                            <span class="mwassistant-tool-status">${this.escapeHtml(mw.msg('mwassistant-chat-tool-running'))}</span>
                        </span>
                    </summary>
                    <div class="mwassistant-tool-expanded">
                        <div class="mwassistant-tool-query"><code>${this.escapeHtml(String(displayQuery))}</code></div>
                        <div class="mwassistant-tool-result">
                            <div class="mwassistant-tool-result-header">${this.escapeHtml(mw.msg('mwassistant-chat-tool-result'))}</div>
                            <div class="mwassistant-tool-result-content"><em>${this.escapeHtml(mw.msg('mwassistant-chat-tool-waiting'))}</em></div>
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
                ? ` <span class="mwassistant-tool-elapsed">${this.escapeHtml(mw.msg('mwassistant-chat-tool-elapsed', elapsedMs))}</span>`
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
                <span class="mwassistant-thinking-label">${this.escapeHtml(label || mw.msg('mwassistant-chat-thinking'))}</span>
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

            // Enter key = send (Shift+Enter inserts a newline). Ignore Enter
            // while an IME composition is active — it confirms the
            // composition rather than meaning "send".
            $root.on('keydown', '#mwassistant-chat-input-text', (e) => {
                const ev = e.originalEvent || e;
                if (e.key === 'Enter' && !e.shiftKey && !ev.isComposing && e.keyCode !== 229) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });

            // Copy buttons (delegated)
            $root.on('click', '.mwassistant-copy-btn', function () {
                const $btn = $(this);
                const code = $btn.siblings('pre').text();

                if (!navigator.clipboard) {
                    $btn.text(mw.msg('mwassistant-chat-copy-error'));
                    setTimeout(() => $btn.text(mw.msg('mwassistant-chat-copy')), 1500);
                    return;
                }
                navigator.clipboard.writeText(code).then(() => {
                    $btn.text(mw.msg('mwassistant-chat-copied'));
                    setTimeout(() => $btn.text(mw.msg('mwassistant-chat-copy')), 1500);
                }).catch(() => {
                    $btn.text(mw.msg('mwassistant-chat-copy-error'));
                    setTimeout(() => $btn.text(mw.msg('mwassistant-chat-copy')), 1500);
                });
            });

            // New chat button
            $root.on('click', '#mwassistant-new-chat', () => this.startNewChat());

            // Session list: each entry is a real <button>, so keyboard
            // activation (Enter/Space) comes for free.
            $root.on('click', '.mwassistant-session-info', (e) => {
                const sessionId = $(e.currentTarget).closest('.mwassistant-session-item').attr('data-session-id');
                if (sessionId) this.loadSession(sessionId);
            });

            // Session delete button
            $root.on('click', '.mwassistant-session-delete', (e) => {
                e.stopPropagation();
                const sessionId = $(e.currentTarget).attr('data-session-id');
                if (sessionId) this.deleteSession(sessionId);
            });
        }

        /* ------------------------------------------------------------------
         * Message Sending
         * ------------------------------------------------------------------ */

        async sendMessage() {
            // Guard against double submits: the Enter key path never sees the
            // disabled Send button, so track the in-flight request ourselves.
            if (this._inFlight) return;

            const $input = this.$container.find('#mwassistant-chat-input-text');
            const text = $input.val().trim();
            if (!text) return;

            // Each request gets its own id + AbortController. Starting a new
            // chat or loading another session aborts it and bumps the id, so
            // any late events from the old stream are ignored instead of
            // being written into the new log / overwriting this.sessionId.
            this.abortActiveRequest();
            const requestId = ++this._requestSeq;
            this._activeRequestId = requestId;
            const controller = typeof window.AbortController === 'function'
                ? new AbortController()
                : null;
            this._abortController = controller;
            const isStale = () => this._activeRequestId !== requestId;

            $input.val('');
            this.appendMessage('user', text);
            this.setBusy(true);

            try {
                if (typeof window.fetch === 'function' && window.ReadableStream) {
                    await this.sendMessageStreaming(text, controller, isStale);
                } else {
                    await this.sendMessageBuffered(text, isStale);
                }
            } catch (err) {
                if (!isStale() && !(err && err.name === 'AbortError')) {
                    console.error('MWAssistant API error:', err);
                    this.showError(mw.msg('mwassistant-chat-error-unreachable'));
                }
            } finally {
                if (!isStale()) {
                    this._activeRequestId = null;
                    this._abortController = null;
                    this.setBusy(false);
                }
            }
        }

        /**
         * Toggle the "request in flight" state (guard flag + Send button).
         */
        setBusy(busy) {
            this._inFlight = busy;
            this.$container.find('#mwassistant-chat-send').prop('disabled', busy);
        }

        /**
         * Abort the in-flight request (if any) and mark it stale so none of
         * its remaining events reach the UI.
         */
        abortActiveRequest() {
            if (this._abortController) {
                try {
                    this._abortController.abort();
                } catch (e) { /* ignore */ }
            }
            this._abortController = null;
            this._activeRequestId = null;
            this._activeToolNodes.clear();
            this.setBusy(false);
        }

        /**
         * Streaming path: POST to Special:MWAssistantStream and parse SSE
         * frames so we can render each tool step as it happens.
         *
         * @param {string} text
         * @param {AbortController|null} controller
         * @param {Function} isStale Returns true once this request was superseded
         */
        async sendMessageStreaming(text, controller, isStale) {
            const messages = this.buildMessagesArray(text);
            // The CSRF token goes in the JSON body rather than the query
            // string so it stays out of URLs and server access logs.
            const body = {
                messages,
                context: this.context,
                token: mw.user.tokens.get('csrfToken'),
            };
            if (this.sessionId) body.session_id = this.sessionId;

            const url = mw.util.getUrl('Special:MWAssistantStream');

            const res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'text/event-stream',
                },
                body: JSON.stringify(body),
                signal: controller ? controller.signal : undefined,
            });

            if (isStale()) return;

            if (!res.ok || !res.body) {
                let detail = mw.msg('mwassistant-chat-error-unreachable');
                try {
                    const errBody = await res.json();
                    if (errBody && errBody.message) detail = errBody.message;
                } catch (_) { /* ignore */ }
                if (!isStale()) this.showError(detail);
                return;
            }

            this._activeToolNodes.clear();
            let dismissThinking = this.showThinkingIndicator();
            let sawAnswer = false;
            let overflowed = false;

            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            const MAX_BUFFER_BYTES = 4 * 1024 * 1024;
            let buffer = '';

            try {
                while (true) {
                    const { value, done } = await reader.read();
                    if (isStale()) {
                        try { await reader.cancel(); } catch (_) { /* ignore */ }
                        return;
                    }
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });

                    if (buffer.length > MAX_BUFFER_BYTES) {
                        console.warn('MWAssistant: SSE buffer exceeded ' + MAX_BUFFER_BYTES + ' bytes; aborting stream.');
                        overflowed = true;
                        try { await reader.cancel(); } catch (_) { /* ignore */ }
                        break;
                    }

                    let sepIndex;
                    while ((sepIndex = buffer.indexOf('\n\n')) !== -1) {
                        const frame = buffer.slice(0, sepIndex);
                        buffer = buffer.slice(sepIndex + 2);
                        const parsed = this.parseSseFrame(frame);
                        if (!parsed) continue;

                        // Heartbeat just confirms the pipe is alive.
                        if (parsed.event === 'heartbeat') continue;

                        // Hide the placeholder spinner the instant any signal arrives.
                        if (dismissThinking) {
                            dismissThinking();
                            dismissThinking = null;
                        }

                        if (this.handleStreamEvent(parsed.event, parsed.data)) {
                            sawAnswer = true;
                        }

                        // After tool_result the LLM is thinking again until the next event.
                        if (parsed.event === 'tool_result' && !sawAnswer) {
                            dismissThinking = this.showThinkingIndicator();
                        }
                    }
                }
            } finally {
                if (dismissThinking) dismissThinking();
                if (!isStale()) this._activeToolNodes.clear();
            }

            if (isStale()) return;

            if (overflowed) {
                this.showError(mw.msg('mwassistant-chat-error-too-large'));
            } else if (!sawAnswer) {
                // The stream ended without an answer or an error event. The
                // server always sends a heartbeat first, so this covers both
                // an empty body (reverse-proxy / output-buffering issues
                // between PHP and the browser) and an upstream that dropped
                // the connection mid-turn.
                console.warn('MWAssistant: stream closed without an assistant message. If this happens on every request, check server-side buffering (Apache mod_deflate / FastCGI flushpackets). See Special:MWAssistantStream proxy notes.');
                this.showError(mw.msg('mwassistant-chat-error-closed'));
            }
        }

        /**
         * Fallback path (older browsers without fetch streams): use the
         * legacy buffered API endpoint and render once everything finishes.
         */
        async sendMessageBuffered(text, isStale) {
            const payload = this.buildPayload(text);
            const data = await this.mwApi.post(payload);
            if (isStale()) return;
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
         * Dispatch a parsed SSE event into UI updates. Returns true when a
         * user-visible answer (assistant message or error) was rendered, so
         * the caller stops the "thinking" indicator and knows the turn did
         * not end silently.
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
                    return true;
                }
                case 'error': {
                    this.showError((data && data.message) || mw.msg('mwassistant-chat-error-generic'));
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
                this.showError(mw.msg('mwassistant-chat-error-malformed'));
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
            if (result.messages && result.messages.length) {
                const last = result.messages[result.messages.length - 1];
                this.appendMessage(last.role, last.content);
                
                // Update title if this was the first message
                if (result.messages.length <= 2) {
                    // Try to get title from session
                    const session = this.sessions.find(s => s.session_id === this.sessionId);
                    if (session && session.title) {
                        this.$container.find('#mwassistant-chat-title').text(session.title);
                    }
                }
            } else if (result.error) {
                this.showError(result.message || mw.msg('mwassistant-chat-error-generic'));
            } else {
                this.showError(mw.msg('mwassistant-chat-error-malformed'));
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
