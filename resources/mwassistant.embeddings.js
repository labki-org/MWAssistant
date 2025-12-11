$(function () {
    // Infuse the TitleInputWidget to enable autocomplete
    var $input = $('#page-input');
    if ($input.length) {
        OO.ui.infuse($input);
    }
});
