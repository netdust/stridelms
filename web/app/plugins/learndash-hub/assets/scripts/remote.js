jQuery(function ($) {
    $('.ld-banner-dismiss').on('click', function () {
        const target = $(this).data('target')

        $.ajax({
            type: 'POST',
            url: ajaxurl,
            data: {
                action: 'flag_remote_dismiss',
                slug: target,
                _wpnonce: ld_hub_remote.nonce
            },
            beforeSend: function () {
                $('#' + target).hide()
            }
        })
    })
})