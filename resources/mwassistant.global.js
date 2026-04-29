/**
 * MWAssistant – Global "Ask Assistant" shortcut on MediaWiki search bars.
 *
 * Renders a small icon button anchored inside the search input, vertically
 * centered at its right edge. Clicking it sends the user's current query
 * to Special:MWAssistant. The previous "Ask Assistant" pill below the input
 * was getting hidden by the search-suggestion dropdown — placing the icon
 * inside the input keeps it visible while suggestions are open.
 */
(function (mw, $) {
    'use strict';

    var BUTTON_CLASS = 'mwassistant-search-icon-btn';
    var ATTACHED_FLAG = 'mwassistantAttached';

    // Only show once the input looks like a question, not a keyword lookup.
    // Roughly: longer than a single English word so it fires on phrases like
    // "where are the labs" but stays hidden for "physics" or "main page".
    var MIN_LENGTH = 12;

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

    // Inline SVG so we don't depend on an icon font; "sparkles" glyph reads
    // as "AI / magic" across cultures and stays legible at 16px.
    var ICON_SVG = (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" ' +
            'width="16" height="16" aria-hidden="true" focusable="false">' +
            '<path fill="currentColor" d="M10 1.5l1.6 4.4 4.4 1.6-4.4 1.6L10 13.5 8.4 9.1 4 7.5l4.4-1.6L10 1.5zM4.5 12l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8.8-2.2zM15.5 11l.6 1.6 1.6.6-1.6.6-.6 1.6-.6-1.6-1.6-.6 1.6-.6.6-1.6z"/>' +
        '</svg>'
    );

    function buildButton($input) {
        var title = mw.msg('mwassistant-search-icon-title');
        var label = mw.msg('mwassistant-search-icon-label');
        return $('<button>')
            .addClass(BUTTON_CLASS)
            .attr({
                type: 'button',
                title: title,
                'aria-label': label
            })
            .html(ICON_SVG)
            .hide()
            .on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var query = $input.val() || '';
                var url = mw.util.getUrl('Special:MWAssistant');
                if (query) {
                    url += (url.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(query);
                }
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

    function syncVisibility($input, $btn) {
        var val = ($input.val() || '').trim();
        if (val.length >= MIN_LENGTH) {
            if ($btn.is(':hidden')) {
                $btn.fadeIn(120);
            }
        } else if ($btn.is(':visible')) {
            $btn.fadeOut(120);
        }
    }

    // Attach + sync visibility on input / focus. Event delegation is necessary
    // because Vector 2022 mounts the search box via Vue after DOM ready.
    // 'input' fires on every keystroke (including paste / IME), so we don't
    // also need 'keyup'.
    $(document).on(
        'input.mwassistant focus.mwassistant',
        INPUT_DELEGATE_SELECTOR,
        function () {
            var $input = $(this);
            if (!$input.closest(SEARCH_FORM_SELECTORS).length) {
                return;
            }
            var $btn = attachButton($input);
            syncVisibility($input, $btn);
        }
    );

}(mediaWiki, jQuery));
