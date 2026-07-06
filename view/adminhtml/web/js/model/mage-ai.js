define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'Magento_Ui/js/modal/modal',
    'uiRegistry'
], function ($, alert, modal, registry) {
    'use strict';

    var mageAI = {
        options: {
            generateBtnSelector: '.generate-mageai-btn',
            advancedGenerateBtnSelector: '.advanced-generate-mageai-btn',
            imageMetadataBtnSelector: '#mp-mageai-image-metadata-btn',
            queueImageMetadataBtnSelector: '#mp-mageai-queue-image-metadata-btn',
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
         * Adds an Images-section button for analyzing the saved product image.
         *
         * @param {HTMLElement|jQuery} anchorButton
         */
        addImageMetadataButton: function (anchorButton) {
            var $anchor = $(anchorButton || '#mp-modify-image-btn');

            if (!$(this.options.imageMetadataBtnSelector).length) {
                if (!$anchor.length) {
                    return;
                }

                $('<button/>', {
                    type: 'button',
                    id: this.options.imageMetadataBtnSelector.replace('#', ''),
                    class: 'action-default scalable action-secondary',
                    title: $.mage.__('Analyze Images with MageAI and update content')
                }).html('<span>' + $.mage.__('Analyze Images with MageAI and update content') + '</span>')
                    .insertAfter($anchor);
            }

            this.addQueueImageMetadataButton(this.options.imageMetadataBtnSelector);
        },

        /**
         * Adds an Images-section button for queueing asynchronous image metadata generation.
         *
         * @param {HTMLElement|jQuery|string} anchorButton
         */
        addQueueImageMetadataButton: function (anchorButton) {
            var $anchor = $(anchorButton || this.options.imageMetadataBtnSelector);

            if ($(this.options.queueImageMetadataBtnSelector).length) {
                return;
            }
            if (!$anchor.length) {
                return;
            }

            $('<button/>', {
                type: 'button',
                id: this.options.queueImageMetadataBtnSelector.replace('#', ''),
                class: 'action-default scalable action-secondary',
                title: $.mage.__('Queue Image Metadata with MageAI')
            }).html('<span>' + $.mage.__('Queue Image Metadata with MageAI') + '</span>')
                .insertAfter($anchor);
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
         * @param {Array} [attributes]  Attribute codes to collect; defaults to window.mpMageAIAttributes
         * @returns {Object} map of attributeCode → display value
         */
        collectAttributeData: function (attributes) {
            var data = {};
            attributes = attributes || window.mpMageAIAttributes || [];

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
        },

        /**
         * Reads the current product ID from form data or the edit URL.
         *
         * @returns {Number}
         */
        getCurrentProductId: function () {
            var id = $('[name="product[id]"]').val() || $('[name="id"]').val();
            var match;

            if (!id) {
                match = window.location.pathname.match(/\/id\/(\d+)/);
                id = match ? match[1] : 0;
            }

            return parseInt(id, 10) || 0;
        },

        /**
         * Generates configured product attributes from the saved image.
         *
         * @returns {jQuery.Deferred}
         */
        generateImageMetadata: function () {
            var deferred = $.Deferred();
            var productId = this.getCurrentProductId();

            if (!productId) {
                alert({
                    title: $.mage.__('Save Product First'),
                    content: $.mage.__('Please save the product before analyzing its image.')
                });
                deferred.resolve(false);
                return deferred.promise();
            }

            $.ajax({
                url: window.mageAIAnalyzeImageUrl,
                type: 'POST',
                showLoader: true,
                data: {
                    'form_key': FORM_KEY,
                    'product_id': productId
                },
                success: function (response) {
                    if (response.error == false) {
                        mageAI.applyImageMetadata(response.data || {});
                        deferred.resolve(response.data || {});
                    } else {
                        alert({
                            title: $.mage.__('Image Metadata Error'),
                            content: response.data
                        });
                        deferred.resolve(false);
                    }
                },
                error: function (XMLHttpRequest, textStatus, errorThrown) {
                    console.log(errorThrown);
                    deferred.reject(errorThrown);
                }
            });

            return deferred.promise();
        },

        /**
         * Enqueues the current product for asynchronous image metadata generation.
         *
         * @returns {jQuery.Deferred}
         */
        queueImageMetadata: function () {
            var deferred = $.Deferred();
            var productId = this.getCurrentProductId();

            if (!productId) {
                alert({
                    title: $.mage.__('Save Product First'),
                    content: $.mage.__('Please save the product before queueing image metadata generation.')
                });
                deferred.resolve(false);
                return deferred.promise();
            }

            $.ajax({
                url: window.mageAIQueueImageMetadataUrl,
                type: 'POST',
                showLoader: true,
                data: {
                    'form_key': FORM_KEY,
                    'product_id': productId
                },
                success: function (response) {
                    if (response.error == false) {
                        var data = response.data || {};

                        alert({
                            title: $.mage.__('MageAI Metadata Queued'),
                            content: data.message || $.mage.__('Product has been queued for image metadata generation.')
                        });
                        deferred.resolve(data);
                    } else {
                        alert({
                            title: $.mage.__('Image Metadata Queue Error'),
                            content: response.data
                        });
                        deferred.resolve(false);
                    }
                },
                error: function (XMLHttpRequest, textStatus, errorThrown) {
                    console.log(errorThrown);
                    deferred.reject(errorThrown);
                }
            });

            return deferred.promise();
        },

        /**
         * Applies generated image-analysis attributes to the product edit form.
         *
         * @param {Object} data
         */
        applyImageMetadata: function (data) {
            $.each(data.options || {}, function (attributeCode, options) {
                mageAI.setAttributeOptions(attributeCode, options);
            });

            $.each(data.fields || {}, function (attributeCode, value) {
                mageAI.setAttributeField(attributeCode, value);
            });

            alert({
                title: $.mage.__('MageAI Metadata Generated'),
                content: $.mage.__('Configured product attributes were updated. Review and save the product to keep the changes.')
            });
        },

        /**
         * Updates a product form field for any supported frontend input.
         *
         * @param {String} code
         * @param {String|Array} value
         */
        setAttributeField: function (code, value) {
            var $field = $('[name="product[' + code + '][]"], [name="product[' + code + ']"]').first();
            var component;

            if (!$field.length) {
                component = this.getUiComponent(code);
                if (component && typeof component.value === 'function') {
                    component.value($.isArray(value) ? $.map(value, String) : String(value));
                    return;
                }
                this.setHtmlField(code, value);
                return;
            }

            if ($field.is('select')) {
                $field.val($.isArray(value) ? $.map(value, String) : String(value)).trigger('change');
                return;
            }

            if ($field.is('textarea')) {
                this.setHtmlField(code, $.isArray(value) ? value.join(', ') : String(value));
                return;
            }

            $field.val($.isArray(value) ? value.join(', ') : String(value)).trigger('change');
        },

        /**
         * Updates a WYSIWYG/textarea HTML product field.
         *
         * @param {String} code
         * @param {String} value
         */
        setHtmlField: function (code, value) {
            var fieldId = 'product_form_' + code;
            var $textarea = $('#' + fieldId + ', [name="product[' + code + ']"]').first();

            value = $.isArray(value) ? value.join(', ') : String(value);

            if (typeof tinymce !== 'undefined' && tinymce.get(fieldId)) {
                tinymce.get(fieldId).setContent(value);
            }

            if ($textarea.length) {
                $textarea.val(value).trigger('change');
            }
        },

        /**
         * Ensures generated select/multiselect option elements exist.
         *
         * @param {String} attributeCode
         * @param {Array} options
         */
        setAttributeOptions: function (attributeCode, options) {
            var $field = $('[name="product[' + attributeCode + '][]"], [name="product[' + attributeCode + ']"]').first();
            var component = this.getUiComponent(attributeCode);

            if (component && typeof component.options === 'function') {
                this.setUiComponentOptions(component, options);
            }

            if (!$field.length) {
                return;
            }

            $.each(options || [], function (i, option) {
                var id = String(option.id);
                if (!$field.find('option[value="' + id.replace(/"/g, '\\"') + '"]').length) {
                    $field.append($('<option/>', {
                        value: id,
                        text: option.label
                    }));
                }
            });
        },

        /**
         * Resolve a Magento UI component by product attribute code.
         *
         * @param {String} attributeCode
         * @returns {Object|null}
         */
        getUiComponent: function (attributeCode) {
            return registry.get('index = ' + attributeCode) || null;
        },

        /**
         * Ensures generated options exist on a Magento UI select/multiselect component.
         *
         * @param {Object} component
         * @param {Array} options
         */
        setUiComponentOptions: function (component, options) {
            var currentOptions = component.options() || [];
            var existing = {};

            $.each(currentOptions, function (i, option) {
                existing[String(option.value)] = true;
            });

            $.each(options || [], function (i, option) {
                var id = String(option.id);
                if (!existing[id]) {
                    currentOptions.push({
                        value: id,
                        label: option.label,
                        '__disableTmpl': true,
                        level: 0,
                        path: ''
                    });
                    existing[id] = true;
                }
            });

            component.options(currentOptions);
        }
    };

    return mageAI;
});
