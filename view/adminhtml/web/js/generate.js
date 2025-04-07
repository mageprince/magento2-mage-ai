require([
    'jquery',
    'Magento_Ui/js/modal/alert'
], function ($, alert) {
    'use strict';

    $(document).on('click', '.generate-mageai-short-content', function () {
        var descriptionField = $(this).parent().parent().find('iframe').contents().find('body');
        var textareaField = $(this).parent().parent().find('textarea');
        var sku = $("input[name='product[sku]']").val();
        var type = 'short';
        if($(this).attr('id') == 'product_form_description_mageai') {
            type = 'full';
        }
        $.ajax({
            url: window.mageAIAjaxUrl,
            type: 'POST',
            showLoader: true,
            data: {
                'form_key': FORM_KEY,
                'sku': sku,
                'type': type
            },
            success: function(response) {
                if (response.error == false) {
                    var descriptionContent = response.data;
                    descriptionField.html(descriptionContent).change();
                    textareaField.val(descriptionContent).change();
                } else {
                    alert({
                        title: $.mage.__('API Error'),
                        content: response.data
                    });
                }
            },
            error: function(XMLHttpRequest, textStatus, errorThrown) {
                console.log(errorThrown);
            }
        });
    });
});
