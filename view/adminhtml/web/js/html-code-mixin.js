define([
    'jquery'
], function ($) {
    'use strict';

    var htmlCodeMixin = {
        defaults: {
            editProductPageSelector: 'catalog-product-edit',
            newProductPageSelector: 'catalog-product-new'
        },

        /**
         * Determines the visibility of a button based on certain conditions.
         *
         * @returns {boolean}
         */
        isBtnVisible: function () {
            var isEnabled = window.isMpMageAIEnabled,
                isProductPage = $('body').hasClass(this.editProductPageSelector),
                isProductEditPage = $('body').hasClass(this.newProductPageSelector);
            if (isEnabled && (isProductPage || isProductEditPage)) {
                return true;
            }
            return false;
        }
    };

    return function (target) {
        return target.extend(htmlCodeMixin);
    };
});
