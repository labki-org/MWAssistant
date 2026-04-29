/**
 * MWAssistant – Global "Ask Assistant" shortcut on MediaWiki search bars.
 *
 * Adds a small shortcut button that appears once a query exceeds a minimum
 * length, sending the user to Special:MWAssistant pre-filled with their
 * query. The button is intentionally scoped to MediaWiki's known search
 * forms so it never overlaps unrelated text inputs on the page.
 */
(function (mw, $) {
    'use strict';

    var MIN_LENGTH = 10;
    var BUTTON_CLASS = 'mwassistant-search-btn';
    var ATTACHED_FLAG = 'mwassistantAttached';

    // Limit ourselves to MediaWiki's actual search forms. Skins like Vector
    // 2022 may render the header search via Vue, so we use event delegation
    // and re-check the form context on each event rather than caching.
    var SEARCH_FORM_SELECTORS = [
        '#searchform',                // Monobook, generic
        '#simpleSearch',              // Vector legacy header search
        '#p-search',                  // Sidebar search portlet
        '.mw-search-form-wrapper',    // Special:Search
        '.vector-search-box'          // Vector 2022
    ].join(', ');

    var INPUT_DELEGATE_SELECTOR = 'input[name="search"], input[type="search"], #searchInput';

    function buildButton($input) {
        return $('<button>')
            .addClass(BUTTON_CLASS + ' mw-ui-button mw-ui-quiet')
            .attr({
                type: 'button',
                title: 'Ask the AI Assistant about this query'
            })
            .text('Ask Assistant')
            .hide()
            .on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var query = $input.val();
                if (!query) {
                    return;
                }
                var url = mw.util.getUrl('Special:MWAssistant');
                url += (url.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(query);
                window.location.href = url;
            });
    }

    function attachButton($input) {
        if ($input.data(ATTACHED_FLAG)) {
            return $input.data('mwassistantBtn');
        }
        var $btn = buildButton($input);
        $input.data(ATTACHED_FLAG, true).data('mwassistantBtn', $btn);
        $input.parent().addClass('mwassistant-search-host').append($btn);
        return $btn;
    }

    $(document).on('input.mwassistant keyup.mwassistant focus.mwassistant', INPUT_DELEGATE_SELECTOR, function () {
        var $input = $(this);

        // Only attach inside known MediaWiki search forms.
        if (!$input.closest(SEARCH_FORM_SELECTORS).length) {
            return;
        }

        var $btn = attachButton($input);
        var val = $input.val() || '';

        if (val.length >= MIN_LENGTH) {
            if ($btn.is(':hidden')) {
                $btn.fadeIn(150);
            }
        } else if ($btn.is(':visible')) {
            $btn.fadeOut(150);
        }
    });

}(mediaWiki, jQuery));
