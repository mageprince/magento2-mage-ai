define([
    'jquery',
    'mage/url',
    'Magento_Ui/js/modal/alert'
], function ($, url, alert) {
    'use strict';

    var htmlCodeMixin = {
        defaults: {
            editProductPageSelector: 'catalog-product-edit',
            newProductPageSelector: 'catalog-product-new',
            wysiwigDivSelector: '.admin__control-wysiwig',
            bodySelector: $("body")
        },

        isBtnVisible: function () {
            var isEnabled = window.isMpMageAIEnabled,
                isProductPage = $('body').hasClass(this.editProductPageSelector),
                isProductEditPage = $('body').hasClass(this.newProductPageSelector);
            if (isEnabled && (isProductPage || isProductEditPage)) {
                return true;
            }
            return false;
        },

        clickMageAIGenerateContent: function (data, event) {
            var self = this;
            var sku = $("input[name='product[sku]']").val();
            $.ajax({
                url: window.mageAIAjaxUrl,
                type: 'POST',
                showLoader: true,
                data: {
                    'form_key': FORM_KEY,
                    'sku': sku,
                    'type': 'full'
                },
                success: function(response) {
                    if (response.error == false) {
                        var targetField = event.currentTarget;
                        self._setMageAIContent(response.data, targetField);
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
        },

        _setMageAIContent: function (content, targetField) {
            var descriptionField = $(targetField).parents(this.wysiwigDivSelector).next('textarea');
            descriptionField.val(content).change();
        }
    };

    return function (target) {
        return target.extend(htmlCodeMixin);
    };
});
