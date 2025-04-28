define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'Magento_Ui/js/modal/modal'
], function ($, alert, modal) {
    'use strict';

    var mageAI = {
        options: {
            generateBtnSelector: '.generate-mageai-btn',
            advancedGenerateBtnSelector: '.advanced-generate-mageai-btn',
            advancedGenerateModalSelector: '#advanced-generate-modal',
            promptGenerateTextAreaSelector: '#mp-custom-prompt',
            shortDescriptionFieldIdentifier: 'product_form_short_description_mageai'
        },

        /**
         * Opens a modal popup for advanced generation functionality. The modal contains
         * a button that triggers content generation using MageAI.
         *
         * @param {string} targetField
         */
        clickAdvancedGenerateButton: function (targetField) {
            var self = this;
            var modalOptions = {
                type: 'popup',
                responsive: true,
                title: $.mage.__('Custom Content Prompt'),
                modalClass: 'mp-mageai-genereate-modal',
                buttons: [{
                    text: $.mage.__('Generate with MageAI'),
                    class: 'action-default secondary',
                    click: function () {
                        self.promptGenerateButtonClick(targetField);
                    }
                }]
            };

            var popup = modal(modalOptions, $(this.options.advancedGenerateModalSelector));
            $(this.options.advancedGenerateModalSelector).modal('openModal');
        },

        /**
         * Handles the click event for the prompt generation button.
         *
         * @param {string} targetField
         */
        promptGenerateButtonClick: function (targetField) {
            var self = this;
            var customPrompt = $(this.options.promptGenerateTextAreaSelector).val().trim();
            var validPrompt = mageAI.validateCustomPrompt(customPrompt);
            if (validPrompt) {
                this.generateContent(false, false, customPrompt)
                    .done(function (content) {
                        if (content) {
                            self.updateDescription(content, targetField);
                        }
                    })
                    .fail(function (error) {
                        console.error('Error generating content:', error);
                    });
            }
        },

        /**
         * Updates the description field based on the specified content and target field.
         *
         * This method determines if the current field is part of the "Page Builder"
         * or a regular form and updates the appropriate description fields accordingly.
         *
         * @param {string} content
         * @param {HTMLElement|string} targetField
         */
        updateDescription: function (content, targetField) {
            var isPageBuilder = false;
            if ($(targetField).parent().attr('id') == 'buttonspagebuilder_html_form_html') {
                isPageBuilder = true;
            }

            if (isPageBuilder) {
                var descriptionField = $(targetField).parents().next('textarea');
                descriptionField.val(content).change();
            } else {
                var descriptionField = $(targetField).parent().parent().find('iframe').contents().find('body');
                console.log(descriptionField);
                var textareaField = $(targetField).parent().parent().find('textarea');
                descriptionField.html(content).change();
                textareaField.val(content).change();
            }
        },

        /**
         * Validates a custom prompt input.
         *
         * @param {string} prompt
         * @returns {boolean}
         */
        validateCustomPrompt: function (prompt) {
            if (!prompt) {
                alert({
                    title: $.mage.__('Please enter custom prompt'),
                    content: ''
                });
                return false;
            }
            return true;
        },

        /**
         * Perform AJAX request to generate content.
         * Sends SKU, type, or custom prompt to server and updates UI with response.
         * @param {string|false} sku - Product SKU or false if not applicable
         * @param {string|false} type - Description type ('short' or 'full') or false
         * @param {string|false} prompt - Custom prompt text or false
         */
        generateContent: function (sku, type, prompt) {
            var self = this;
            var deferred = $.Deferred();
            $.ajax({
                url: window.mageAIAjaxUrl,
                type: 'POST',
                showLoader: true,
                data: {
                    'form_key': FORM_KEY,
                    'sku': sku,
                    'type': type,
                    'custom_prompt': prompt
                },
                success: function(response) {
                    if (response.error == false) {
                        deferred.resolve(response.data);
                    } else {
                        alert({
                            title: $.mage.__('API Error'),
                            content: response.data
                        });
                        deferred.resolve(false);
                    }
                    if (prompt) {
                        $(self.options.advancedGenerateModalSelector).modal('closeModal');
                    }
                    return false;
                },
                error: function(XMLHttpRequest, textStatus, errorThrown) {
                    console.log(errorThrown);
                    deferred.reject(errorThrown);
                }
            });

            return deferred.promise();
        }
    };

    return mageAI;
});
