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
         * Opens a modal popup for advanced generation functionality.
         *
         * @param {HTMLElement} targetField
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

            modal(modalOptions, $(this.options.advancedGenerateModalSelector));
            $(this.options.advancedGenerateModalSelector).modal('openModal');
        },

        /**
         * Handles the click event for the custom prompt generate button.
         *
         * @param {HTMLElement} targetField
         */
        promptGenerateButtonClick: function (targetField) {
            var self = this;
            var customPrompt = $(this.options.promptGenerateTextAreaSelector).val().trim();

            if (mageAI.validateCustomPrompt(customPrompt)) {
                this.generateContent({}, false, customPrompt)
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
         * Collects current product attribute values from the form DOM.
         * Reads display values (option labels for selects) so no server-side
         * option ID resolution is needed. Skips attributes whose form fields
         * are not present on the page (not in the attribute set, etc.).
         *
         * @returns {Object} map of attributeCode → display value
         */
        collectAttributeData: function () {
            var data = {};
            var attributes = window.mpMageAIAttributes || [];

            $.each(attributes, function (i, code) {
                var value = mageAI.getAttributeFormValue(code);
                if (value !== null && value !== '') {
                    data[code] = value;
                }
            });

            return data;
        },

        /**
         * Reads the display value for a single product attribute from the form.
         * Returns null if the field is not present (attribute not in attribute set).
         *
         * @param {string} code  Attribute code
         * @returns {string|null}
         */
        getAttributeFormValue: function (code) {
            // WYSIWYG fields (e.g. description) — use TinyMCE API when available
            if (typeof tinymce !== 'undefined') {
                var editor = tinymce.get('product_form_' + code);
                if (editor) {
                    var text = $('<div>').html(editor.getContent()).text().trim();
                    return text || null;
                }
            }

            // Standard field: input, textarea, select
            var $field = $('[name="product[' + code + ']"]');

            // Multiselect uses array syntax: product[code][]
            if (!$field.length) {
                $field = $('[name="product[' + code + '][]"]');
            }

            if (!$field.length) {
                return null;
            }

            if ($field.is('select[multiple]')) {
                var labels = [];
                $field.find('option:selected').each(function () {
                    var label = $.trim($(this).text());
                    if (label) {
                        labels.push(label);
                    }
                });
                return labels.length ? labels.join(', ') : null;
            }

            if ($field.is('select')) {
                var selected = $field.find('option:selected').text().trim();
                return selected || null;
            }

            var val = $field.val();
            return (val !== null && String(val).trim() !== '') ? String(val).trim() : null;
        },

        /**
         * Updates the description field with generated content.
         * Handles both WYSIWYG and Page Builder targets.
         *
         * @param {string} content
         * @param {HTMLElement|string} targetField
         */
        updateDescription: function (content, targetField) {
            var isPageBuilder = $(targetField).parent().attr('id') === 'buttonspagebuilder_html_form_html';

            if (isPageBuilder) {
                $(targetField).parents().next('textarea').val(content).change();
            } else {
                var $iframe = $(targetField).parent().parent().find('iframe');
                var $textarea = $(targetField).parent().parent().find('textarea');
                $iframe.contents().find('body').html(content).change();
                $textarea.val(content).change();
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
         * Performs the AJAX request to the generate controller.
         *
         * @param {Object}        attributeData  Product attribute values from the form ({} for custom prompts)
         * @param {string|false}  type           'short', 'full', or false for custom prompts
         * @param {string|false}  prompt         Custom prompt text, or false for attribute-based generation
         * @returns {jQuery.Deferred}
         */
        generateContent: function (attributeData, type, prompt) {
            var self = this;
            var deferred = $.Deferred();

            $.ajax({
                url: window.mageAIAjaxUrl,
                type: 'POST',
                showLoader: true,
                data: {
                    'form_key': FORM_KEY,
                    'attribute_data': attributeData || {},
                    'type': type,
                    'custom_prompt': prompt
                },
                success: function (response) {
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
                error: function (XMLHttpRequest, textStatus, errorThrown) {
                    console.log(errorThrown);
                    deferred.reject(errorThrown);
                }
            });

            return deferred.promise();
        }
    };

    return mageAI;
});
