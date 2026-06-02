define([
    'jquery',
    'underscore',
    'mage/translate'
], function ($, _, $t) {
    'use strict';

    var BUTTONS_CONTAINER = '#buttonspagebuilder_html_form_html';
    var MAX_ATTEMPTS = 10;
    var RETRY_INTERVAL = 100;

    var htmlCodeMixin = {
        defaults: {
            editProductPageSelector: 'catalog-product-edit',
            newProductPageSelector: 'catalog-product-new'
        },

        initialize: function () {
            this._super();
            if (this.isBtnVisible()) {
                this._attemptButtonInjection(0);
            }
            return this;
        },

        /**
         * Polls until the buttons container is in the DOM, then injects buttons.
         * Retries up to MAX_ATTEMPTS times to handle async KO template rendering.
         *
         * @param {number} attempt
         */
        _attemptButtonInjection: function (attempt) {
            var self = this,
                $container = $(BUTTONS_CONTAINER);

            if ($container.length) {
                this._injectButtons($container);
            } else if (attempt < MAX_ATTEMPTS) {
                _.delay(function () {
                    self._attemptButtonInjection(attempt + 1);
                }, RETRY_INTERVAL);
            }
        },

        /**
         * Appends MageAI generate buttons to the Page Builder buttons container.
         * Guards against duplicate injection on re-render.
         *
         * @param {jQuery} $container
         */
        _injectButtons: function ($container) {
            if ($container.find('.generate-mageai-btn').length) {
                return;
            }
            $container.append(
                '<button type="button" class="scalable generate-mageai-btn">' +
                    '<span><span><span>' + $t('Generate with MageAI') + '</span></span></span>' +
                '</button>' +
                '<button type="button" class="scalable advanced-generate-mageai-btn">' +
                    '<span><span><span>' + $t('Advanced Generate with MageAI') + '</span></span></span>' +
                '</button>'
            );
        },

        /**
         * Returns true only on product create/edit pages when the extension is enabled.
         *
         * @returns {boolean}
         */
        isBtnVisible: function () {
            var isEnabled = window.isMpMageAIEnabled,
                isEditPage = $('body').hasClass(this.editProductPageSelector),
                isNewPage = $('body').hasClass(this.newProductPageSelector);

            return !!(isEnabled && (isEditPage || isNewPage));
        }
    };

    return function (target) {
        return target.extend(htmlCodeMixin);
    };
});
