define([
    'jquery',
    'Mageprince_MageAI/js/model/mage-ai',
    'Magento_Ui/js/modal/alert',
    'Magento_Ui/js/modal/modal'
], function ($, mageAIModel, alert, modal) {
    'use strict';

    $.widget('mage.mageAiImageGenerate', {
        options: {
            modalSelector: '#mp-image-generate-modal',
            gallerySelector: '[data-mage-init*="productGallery"]',
            buttonContainerSelector: '.add-video-button-container',
            buttonId: 'mp-generate-image-btn',
            generateImageUrl: window.mageAIGenerateImageUrl || ''
        },

        /**
         * Widget initialization: inject the button and listen for deferred gallery renders.
         */
        _create: function () {
            this._injectButton();
            $('body').on('contentUpdated', this._injectButton.bind(this));
        },

        /**
         * Inject the "Generate Image with MageAI" button directly after the Add Video button,
         * inside the same container so it inherits the admin layout/positioning.
         * Idempotent — skips injection when the button already exists.
         */
        _injectButton: function () {
            if ($('#' + this.options.buttonId).length) {
                return;
            }

            var $addVideoBtn = $('#add_video_button');
            if (!$addVideoBtn.length) {
                return;
            }

            var $btn = $('<button>', {
                id: this.options.buttonId,
                type: 'button',
                'class': 'action-secondary mp-generate-image-btn',
                title: $.mage.__('Generate Image with MageAI')
            }).html('<span>' + $.mage.__('Generate Image with MageAI') + '</span>');

            $addVideoBtn.after($btn);

            this._bindGenerateButton();
        },

        /**
         * Bind the click handler for the injected button (delegated so it survives re-renders).
         */
        _bindGenerateButton: function () {
            var self = this;
            $(document).off('click.mageAiImage', '#' + this.options.buttonId)
                .on('click.mageAiImage', '#' + this.options.buttonId, function () {
                    self._openModal();
                });
        },

        /**
         * Open the image generation modal. Initializes it on first use.
         */
        _openModal: function () {
            var self = this;
            var $modal = $(this.options.modalSelector);

            if (!$modal.data('mpImageModalInited')) {
                modal({
                    type: 'popup',
                    responsive: true,
                    title: $.mage.__('Generate Image with MageAI'),
                    modalClass: 'mp-mageai-image-modal',
                    buttons: [{
                        text: $.mage.__('Generate with MageAI'),
                        class: 'action-primary mp-generate-image-submit',
                        click: function () {
                            self._generate();
                        }
                    }]
                }, $modal);
                $modal.data('mpImageModalInited', true);
            }

            $modal.modal('openModal');
        },

        /**
         * Run the image generation AJAX call and, on success, add the image to the product gallery.
         */
        _generate: function () {
            var self = this;
            var prompt = $('#mp-image-prompt').val().trim();
            var productName = $('[name="product[name]"]').val() || '';
            var attributeData = mageAIModel.collectAttributeData(window.mpMageAIImageAttributes);

            $.ajax({
                url: this.options.generateImageUrl,
                type: 'POST',
                showLoader: true,
                data: {
                    'form_key': FORM_KEY,
                    'custom_prompt': prompt,
                    'product_name': productName,
                    'attribute_data': attributeData
                },
                success: function (response) {
                    if (response && response.error) {
                        alert({
                            title: $.mage.__('Image Generation Error'),
                            content: response.data
                        });
                        return;
                    }

                    if (response && response.file) {
                        self._addImageToGallery(response);
                        $(self.options.modalSelector).modal('closeModal');
                        $('#mp-image-prompt').val('');
                    } else {
                        alert({
                            title: $.mage.__('Error'),
                            content: $.mage.__('Unexpected response from server. Please try again.')
                        });
                    }
                },
                error: function () {
                    alert({
                        title: $.mage.__('Error'),
                        content: $.mage.__('Failed to communicate with the server. Please try again.')
                    });
                }
            });
        },

        /**
         * Trigger the productGallery addItem event with the image data returned by the controller.
         * This mirrors how the gallery file uploader adds images.
         *
         * @param {Object} imageData
         */
        _addImageToGallery: function (imageData) {
            var $gallery = $(this.options.gallerySelector).first();
            if (!$gallery.length) {
                // Fallback: try common ID used by Magento's gallery block
                $gallery = $('#media_gallery_content');
            }
            if ($gallery.length) {
                $gallery.trigger('addItem', imageData);
            }
        }
    });

    return $.mage.mageAiImageGenerate;
});
