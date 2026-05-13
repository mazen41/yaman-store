(function( $ ) {
    'use strict';
    
    /*Show/hide settings for post format when choose post format*/
    var $format = $('#post-formats-select').find('input.post-format'),
        $formatBox = $('#format_detail');

    /* WordPress old version for Classic Editor */

    $format.on('change', function () {
        var type = $(this).filter(':checked').val();
        postFormatSettings(type);
    });

    $( window ).on('load', function() {
        var type = $format.filter(':checked').val();
        postFormatSettings(type);
    });

    $format.filter(':checked').trigger('change');

    /* End old WordPress version */

    $(document.body).on('change', '.editor-post-format input.components-radio-control__input', function () {
        var type = $(this).val();
        postFormatSettings(type);
    });

    $( window ).on('load', function () {
        var $el = $(document.body).find('.editor-post-format input.components-radio-control__input'),
            type = $el.val();
        postFormatSettings(type);
    });

    function postFormatSettings(type) {
        $formatBox.hide();
        if ($formatBox.find('.rwmb-field').hasClass(type)) {
            $formatBox.show();
        }
        $formatBox.find('.rwmb-field').slideUp();
        $formatBox.find('.' + type).slideDown();
    }

})(jQuery);
