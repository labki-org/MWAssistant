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
            this.$button = null;
            this.chatInstance = null;
            this.init();
        }

        init() {
            const action = mw.config.get("wgAction");
            if (action !== "edit" && action !== "submit") {
                return; // Only attach in edit mode
            }
            this.bindGlobalShortcuts();

            // WikiEditor builds its toolbar asynchronously, so injecting at
            // DOM-ready raced it (the button landed in the fallback strip,
            // or nowhere). Wait for its toolbar-ready hook when WikiEditor
            // is on the page; mw.hook remembers past fires, so this also
            // works if the toolbar was already built.
            const wikiEditorState = mw.loader.getState("ext.wikiEditor");
            const wikiEditorPending = wikiEditorState !== null && wikiEditorState !== "registered";
            if (wikiEditorPending) {
                mw.hook("ext.WikiEditor.toolbar.ready").add(($textarea) => this.addButton($textarea));
            }

            // Fallback for the plain textarea (no WikiEditor) or if the hook
            // never fires (WikiEditor failed / disabled by preference).
            $(() => {
                if (!wikiEditorPending) {
                    this.addButton();
                    return;
                }
                mw.loader.using("ext.wikiEditor").always(() => {
                    setTimeout(() => this.addButton(), 3000);
                });
            });
        }

        /* ------------------------------------------------------------------
         * Toolbar / Button Injection
         * ------------------------------------------------------------------ */

        findToolbar($textarea) {
            // 1. WikiEditor toolbar belonging to the textarea from the hook
            let $toolbar = $textarea && $textarea.length
                ? $textarea.closest(".wikiEditor-ui").find(".wikiEditor-ui-toolbar").first()
                : $();

            // 2. Standard Wikitext toolbar
            if (!$toolbar.length) {
                $toolbar = $("#wpTextbox1").closest("form").find(".toolbar");
            }

            // 3. Any WikiEditor toolbar
            if (!$toolbar.length) {
                $toolbar = $(".wikiEditor-ui-toolbar");
            }

            // 4. OOUI toolbar (Vector 2022)
            if (!$toolbar.length) {
                $toolbar = $(".oo-ui-toolbar-bar");
            }

            // 5. Fallback: create a small toolbar above the textarea
            if (!$toolbar.length && $("#wpTextbox1").length) {
                $("#wpTextbox1").before('<div class="mwassistant-editor-tools"></div>');
                $toolbar = $("#wpTextbox1").prev(".mwassistant-editor-tools");
            }

            return $toolbar;
        }

        addButton($textarea) {
            if (this.$button) {
                return; // Already injected (hook + fallback both fired).
            }
            const $toolbar = this.findToolbar($textarea);
            if (!$toolbar || !$toolbar.length) {
                return;
            }

            const $btn = this.$button = $("<button>")
                .attr({ type: "button", "aria-expanded": "false", "aria-controls": "mwassistant-editor-sidebar" })
                .addClass("mwassistant-editor-button")
                .text(mw.msg("mwassistant-editor-button"))
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
            if (this.$button) this.$button.attr("aria-expanded", "true");
            // Focus the chat input so the user can type immediately.
            this.$sidebar.find('#mwassistant-chat-input-text').trigger('focus');
        }

        hideSidebar() {
            if (!this.$sidebar) return;
            const hadFocus = $.contains(this.$sidebar[0], document.activeElement);
            this.$sidebar.hide();
            if (this.$button) {
                this.$button.attr("aria-expanded", "false");
                // Don't strand keyboard focus inside the hidden sidebar.
                if (hadFocus) this.$button.trigger("focus");
            }
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
            this.$sidebar = $('<div id="mwassistant-editor-sidebar" role="complementary"></div>')
                .attr("aria-label", mw.msg("mwassistant-chat-title"));

            const fullPageLabel = mw.msg("mwassistant-editor-open-full-page");
            const closeLabel = mw.msg("mwassistant-editor-close");
            const $header = $('<div class="mwassistant-editor-sidebar-header"></div>').append(
                $('<span class="mwassistant-editor-sidebar-title"></span>')
                    .text(mw.msg("mwassistant-chat-title")),
                $('<a class="mwassistant-editor-sidebar-fullpage">↗</a>')
                    .attr({
                        href: mw.util.getUrl("Special:MWAssistant"),
                        title: fullPageLabel,
                        "aria-label": fullPageLabel
                    }),
                $('<button type="button" class="mwassistant-editor-sidebar-close">×</button>')
                    .attr({ title: closeLabel, "aria-label": closeLabel })
            );
            $header.find(".mwassistant-editor-sidebar-close").on("click", () => this.hideSidebar());
            this.$sidebar.append($header);

            const $chatContainer = $('<div id="mwassistant-editor-chat"></div>');
            this.$sidebar.append($chatContainer);

            $("body").append(this.$sidebar);

            this.initChat($chatContainer);

            this.sidebarInitialized = true;
        }

        initChat($chatContainer) {
            if (!mw.mwAssistant || !mw.mwAssistant.Chat) {
                $chatContainer.text(mw.msg("mwassistant-editor-chat-unavailable"));
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
