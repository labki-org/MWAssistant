(function (mw, $) {
    console.log('MWAssistant Global: Script execution started');

    $(function () {
        console.log('MWAssistant Global: Document Ready');

        var MIN_LENGTH = 10;
        var buttonId = 'mwassistant-search-btn';

        // Use event delegation to handle Vue re-renders or dynamic inputs
        $(document).on('input keyup focus', 'input[name="search"], input[type="search"], #searchInput', function () {
            var $this = $(this);
            var val = $this.val() || '';
            // console.log('MWAssistant Global: Input event. Length:', val.length);

            // Find or related button
            // The button might be a sibling or inside the same wrapper. 
            // Since we might have multiple search inputs (mobile/desktop), we need to be careful.
            // Let's create a unique ID for the button related to *this* input if possible? 
            // actually, let's just look for the button relative to this input.

            var $btn = $this.parent().find('.' + buttonId);
            if (!$btn.length) {
                // If button lost (re-render) or not created, ensure it exists
                ensureButton($this);
                $btn = $this.parent().find('.' + buttonId);
            }

            if (val.length >= MIN_LENGTH) {
                if ($btn.is(':hidden')) {
                    console.log('MWAssistant Global: Showing button');
                    $btn.fadeIn(200);
                }
            } else {
                if ($btn.is(':visible')) {
                    console.log('MWAssistant Global: Hiding button');
                    $btn.fadeOut(200);
                }
            }
        });

        // Function to inject button
        function ensureButton($input) {
            // Check if already exists nearby
            if ($input.parent().find('.' + buttonId).length) {
                return;
            }

            console.log('MWAssistant Global: Injecting button for', $input.attr('id') || $input.attr('name'));

            var $assistantBtn = $('<button>')
                .addClass(buttonId + ' mw-ui-button mw-ui-quiet')
                .text('Ask Assistant')
                .attr('title', 'Ask the AI Assistant about this query')
                .hide()
                .on('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation(); // Stop propagation to prevent search submit
                    var query = $input.val();
                    if (query) {
                        var targetUrl = mw.util.getUrl('Special:MWAssistant');
                        window.location.href = targetUrl + (targetUrl.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(query);
                    }
                });

            // Insert after input
            $input.after($assistantBtn);

            // Fix parent position for absolute button
            if ($input.parent().css('position') === 'static') {
                $input.parent().css('position', 'relative');
            }
        }

        // Periodic check to re-inject if lost (fallback for when user is NOT typing)
        setInterval(function () {
            var $inputs = $('input[name="search"], input[type="search"], #searchInput');
            $inputs.each(function () {
                ensureButton($(this));
            });
        }, 2000);

    });
}(mediaWiki, jQuery));
