/**
 * MWAssistant Embeddings dashboard frontend.
 *
 * Only responsibility right now: infuse the OOUI TitleInputWidget so the
 * single-page form gets autocomplete. The "queued" banner is fully
 * server-rendered and intentionally static — the user reloads when they
 * want fresh stats.
 */
$( function () {
    var $input = $( '#page-input' );
    if ( $input.length ) {
        OO.ui.infuse( $input );
    }
} );
