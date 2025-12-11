/**
 * MWAssistant – Edit Page Sidebar Chat Assistant
 *
 * Enhancements:
 *  - Modern ES6 class structure
 *  - Reliable toolbar detection
 *  - Clean sidebar layout management
 *  - Safer initialization and teardown logic
 *  - Improved editor context extraction
 *  - Consistent, isolated helper functions
 */

(function (mw, $) {

    /* ======================================================================
     * Utility helpers
     * ====================================================================== */

    /** Return selected text inside a <textarea>. */
    function getSelectionText(textarea) {
        if (!textarea || textarea.selectionStart === undefined) {
            return "";
        }
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        return (start !== end) ? textarea.value.substring(start, end) : "";
    }

    /** Create reliably-namespaced log output */
    function log(...args) {
        console.log("[MWAssistant Editor]", ...args);
    }

    /** Error logging */
    function error(...args) {
        console.error("[MWAssistant Editor ERROR]", ...args);
    }


    /* ======================================================================
     * Main Class
     * ====================================================================== */

    class MWAssistantEditor {

        constructor() {
            this.sidebarInitialized = false;
            this.$sidebar = null;
            this.chatInstance = null;
            this.mwApi = new mw.Api();

            this.init();
        }

        /* ------------------------------------------------------------------
         * Initialization
         * ------------------------------------------------------------------ */

        init() {
            const action = mw.config.get("wgAction");
            if (action !== "edit" && action !== "submit") {
                return; // Only attach in edit mode
            }

            this.addButton();
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

            // 4. Fallback: create a small toolbar
            if (!$toolbar.length && $("#wpTextbox1").length) {
                log("Fallback toolbar created.");
                $("#wpTextbox1").before('<div class="mwassistant-editor-tools"></div>');
                $toolbar = $("#wpTextbox1").prev(".mwassistant-editor-tools");
            }

            return $toolbar;
        }

        addButton() {
            log("Attempting to inject editor button...");

            const $toolbar = this.findToolbar();
            if (!$toolbar || !$toolbar.length) {
                error("Editor toolbar not found; aborting button injection.");
                return;
            }

            const $btn = $("<button>")
                .attr("type", "button")
                .addClass("mwassistant-editor-button mw-ui-button")
                .text("Ask Assistant")
                .on("click", (e) => this.toggleSidebar(e));

            if ($toolbar.hasClass("oo-ui-toolbar-bar")) {
                $toolbar.prepend($btn);
                $btn.css({
                    position: "relative",
                    "z-index": 999,
                    margin: "5px"
                });
            } else {
                $toolbar.append($btn);
            }

            log("Editor button successfully added.");
        }

        /* ------------------------------------------------------------------
         * Sidebar Handling
         * ------------------------------------------------------------------ */

        toggleSidebar(event) {
            event.preventDefault();

            if (!this.sidebarInitialized) {
                this.initSidebar();
                // Immediately show after init, regardless of CSS state
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
            $("#wpTextbox1").focus();
        }

        hideSidebar() {
            if (!this.$sidebar) return;
            this.$sidebar.hide();
        }

        /* ------------------------------------------------------------------
         * Sidebar Initialization
         * ------------------------------------------------------------------ */

        initSidebar() {
            log("Initializing sidebar…");

            // Sidebar shell
            this.$sidebar = $('<div id="mwassistant-editor-sidebar"></div>');

            // Header with close button
            const $header = $(`
                <div class="mwassistant-sidebar-header">
                    <span class="mwassistant-sidebar-title">Assistant</span>
                    <button type="button" class="mwassistant-sidebar-close">×</button>
                </div>
            `);

            $header.find(".mwassistant-sidebar-close").on("click", () => this.hideSidebar());
            this.$sidebar.append($header);

            // Chat container
            const $chatContainer = $('<div id="mwassistant-editor-chat"></div>');
            this.$sidebar.append($chatContainer);

            // Append to DOM
            $("body").append(this.$sidebar);

            // Initialize Chat UI
            this.initChat($chatContainer);

            this.sidebarInitialized = true;
        }

        /* ------------------------------------------------------------------
         * Chat Initialization w/ Context
         * ------------------------------------------------------------------ */

        initChat($chatContainer) {
            if (!mw.mwAssistant?.Chat) {
                $chatContainer.text("Error: MWAssistant Chat module is not loaded.");
                return;
            }

            const pageTitle = mw.config.get("wgPageName");

            this.chatInstance = new mw.mwAssistant.Chat({
                $container: $chatContainer,
                systemPrompt: [
                    {
                        role: "system",
                        content:
                            "You are an intelligent MediaWiki assistant helping a user edit a wiki page. " +
                            "Your PRIMARY goal is to provide specific MediaWiki syntax help—templates, categories, " +
                            "Semantic MediaWiki queries, wikitext formatting, etc. " +
                            "Provide correct wikitext examples the user can copy. " +
                            "DO NOT attempt to edit the page directly via tool calls."
                    }
                ],
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

    /* ======================================================================
     * Auto-init
     * ====================================================================== */

    $(function () {
        new MWAssistantEditor();
    });

})(mediaWiki, jQuery);
