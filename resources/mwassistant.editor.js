/**
 * MWAssistant – Edit Page Sidebar Chat Assistant
 *
 * Adds an "Ask Assistant" button to the editor toolbar that opens a fixed
 * sidebar containing the shared chat UI, pre-loaded with the current page's
 * wikitext (and any selected range) as conversational context.
 */

(function (mw, $) {

    /** Return selected text inside a <textarea>. */
    function getSelectionText(textarea) {
        if (!textarea || textarea.selectionStart === undefined) {
            return "";
        }
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        return (start !== end) ? textarea.value.substring(start, end) : "";
    }

    class MWAssistantEditor {

        constructor() {
            this.sidebarInitialized = false;
            this.$sidebar = null;
            this.chatInstance = null;
            this.init();
        }

        init() {
            const action = mw.config.get("wgAction");
            if (action !== "edit" && action !== "submit") {
                return; // Only attach in edit mode
            }
            this.addButton();
            this.bindGlobalShortcuts();
        }

        /* ------------------------------------------------------------------
         * Toolbar / Button Injection
         * ------------------------------------------------------------------ */

        findToolbar() {
            // 1. Standard Wikitext toolbar
            let $toolbar = $("#wpTextbox1").closest("form").find(".toolbar");

            // 2. WikiEditor toolbar
            if (!$toolbar.length) {
                $toolbar = $(".wikiEditor-ui-toolbar");
            }

            // 3. OOUI toolbar (Vector 2022)
            if (!$toolbar.length) {
                $toolbar = $(".oo-ui-toolbar-bar");
            }

            // 4. Fallback: create a small toolbar above the textarea
            if (!$toolbar.length && $("#wpTextbox1").length) {
                $("#wpTextbox1").before('<div class="mwassistant-editor-tools"></div>');
                $toolbar = $("#wpTextbox1").prev(".mwassistant-editor-tools");
            }

            return $toolbar;
        }

        addButton() {
            const $toolbar = this.findToolbar();
            if (!$toolbar || !$toolbar.length) {
                return;
            }

            const $btn = $("<button>")
                .attr("type", "button")
                .addClass("mwassistant-editor-button mw-ui-button")
                .text("Ask Assistant")
                .on("click", (e) => this.toggleSidebar(e));

            // Inside the OOUI toolbar (Vector 2022) the button needs to sit
            // above absolutely-positioned siblings; everywhere else, just append.
            if ($toolbar.hasClass("oo-ui-toolbar-bar")) {
                $btn.addClass("mwassistant-editor-button-ooui");
                $toolbar.prepend($btn);
            } else {
                $toolbar.append($btn);
            }
        }

        /* ------------------------------------------------------------------
         * Sidebar Handling
         * ------------------------------------------------------------------ */

        toggleSidebar(event) {
            event.preventDefault();

            if (!this.sidebarInitialized) {
                this.initSidebar();
                this.showSidebar();
                return;
            }

            if (this.$sidebar.is(":visible")) {
                this.hideSidebar();
            } else {
                this.showSidebar();
            }
        }

        showSidebar() {
            if (!this.$sidebar) return;
            this.$sidebar.show();
            // Focus the chat input so the user can type immediately.
            this.$sidebar.find('#mwassistant-chat-input-text').trigger('focus');
        }

        hideSidebar() {
            if (!this.$sidebar) return;
            this.$sidebar.hide();
        }

        bindGlobalShortcuts() {
            // Esc closes the sidebar when it has focus or is visible.
            $(document).on('keydown.mwassistantEditor', (e) => {
                if (e.key !== 'Escape' || !this.$sidebar) return;
                if (!this.$sidebar.is(':visible')) return;
                // Only handle if the sidebar (or the chat input inside it) has focus,
                // so we don't hijack Esc from other widgets.
                if ($.contains(this.$sidebar[0], document.activeElement) ||
                    document.activeElement === this.$sidebar[0]) {
                    this.hideSidebar();
                }
            });
        }

        /* ------------------------------------------------------------------
         * Sidebar Initialization
         * ------------------------------------------------------------------ */

        initSidebar() {
            this.$sidebar = $('<div id="mwassistant-editor-sidebar"></div>');

            const $header = $(`
                <div class="mwassistant-editor-sidebar-header">
                    <span class="mwassistant-editor-sidebar-title">Assistant</span>
                    <a class="mwassistant-editor-sidebar-fullpage"
                       href="${mw.util.getUrl('Special:MWAssistant')}"
                       title="Open full assistant page">↗</a>
                    <button type="button" class="mwassistant-editor-sidebar-close" aria-label="Close">×</button>
                </div>
            `);
            $header.find(".mwassistant-editor-sidebar-close").on("click", () => this.hideSidebar());
            this.$sidebar.append($header);

            const $chatContainer = $('<div id="mwassistant-editor-chat"></div>');
            this.$sidebar.append($chatContainer);

            $("body").append(this.$sidebar);

            this.initChat($chatContainer);

            this.sidebarInitialized = true;
        }

        initChat($chatContainer) {
            if (!mw.mwAssistant?.Chat) {
                $chatContainer.text("Error: MWAssistant Chat module is not loaded.");
                return;
            }

            const pageTitle = mw.config.get("wgPageName");

            this.chatInstance = new mw.mwAssistant.Chat({
                $container: $chatContainer,
                context: 'editor',
                hideSessions: true,
                getExtraContext: () => {
                    const $textarea = $("#wpTextbox1");
                    const text = $textarea.val() || "";
                    const selection = getSelectionText($textarea[0]);

                    let context = `Current Page Title: ${pageTitle}\n`;

                    if (selection) {
                        context += `User Selection:\n${selection}\n\n`;
                    }

                    if (text) {
                        context += "Full Page Content (Truncated):\n" + text.substring(0, 12000);
                    }

                    return context;
                }
            });
        }
    }

    $(function () {
        new MWAssistantEditor();
    });

})(mediaWiki, jQuery);
