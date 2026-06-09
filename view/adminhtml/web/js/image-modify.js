define([
    'jquery',
    'Mageprince_MageAI/js/model/mage-ai',
    'Magento_Ui/js/modal/alert',
    'Magento_Ui/js/modal/modal'
], function ($, mageAIModel, alert, modal) {
    'use strict';

    $.widget('mage.mageAiImageModify', {
        options: {
            modalSelector: '#mp-image-modify-modal',
            gallerySelector: '[data-mage-init*="productGallery"]',
            generateButtonId: 'mp-generate-image-btn',
            buttonId: 'mp-modify-image-btn',
            modifyImageUrl: window.mageAIModifyImageUrl || ''
        },

        /**
         * Widget initialization: inject the button and listen for deferred gallery renders.
         */
        _create: function () {
            this._selected = null;
            this._pendingNew = null;
            this._injectButton();
            $('body').on('contentUpdated', this._injectButton.bind(this));
        },

        /**
         * Inject the "Modify Image with MageAI" button right after the "Generate Image with MageAI"
         * button (falling back to the Add Video button). Idempotent.
         */
        _injectButton: function () {
            if ($('#' + this.options.buttonId).length) {
                return;
            }

            var $anchor = $('#' + this.options.generateButtonId);
            if (!$anchor.length) {
                $anchor = $('#add_video_button');
            }
            if (!$anchor.length) {
                return;
            }

            var $btn = $('<button>', {
                id: this.options.buttonId,
                type: 'button',
                'class': 'action-secondary mp-modify-image-btn',
                title: $.mage.__('Edit Image with MageAI')
            }).html('<span>' + $.mage.__('Edit Image with MageAI') + '</span>');

            $anchor.after($btn);

            this._bindButton();
        },

        /**
         * Bind the click handler for the injected button (delegated so it survives re-renders).
         */
        _bindButton: function () {
            var self = this;
            $(document).off('click.mageAiModify', '#' + this.options.buttonId)
                .on('click.mageAiModify', '#' + this.options.buttonId, function () {
                    self._openModal();
                });
        },

        /**
         * Open the modify modal. Initializes it and its internal event bindings on first use.
         */
        _openModal: function () {
            var self = this,
                $modal = $(this.options.modalSelector);

            if (!$modal.data('mpModifyModalInited')) {
                modal({
                    type: 'popup',
                    responsive: true,
                    title: $.mage.__('Edit Image with MageAI'),
                    modalClass: 'mp-mageai-modify-modal',
                    buttons: []
                }, $modal);
                this._bindModalEvents($modal);
                $modal.data('mpModifyModalInited', true);
            }

            this._selected = null;
            this._pendingNew = null;
            this._renderImageList($modal);
            this._showView($modal, 'list');
            $modal.modal('openModal');
        },

        /**
         * Bind all in-modal interactions once.
         *
         * @param {jQuery} $modal
         */
        _bindModalEvents: function ($modal) {
            var self = this;

            // Pick an image to modify.
            $modal.on('click', '[data-role=modify-pick]', function () {
                var index = $(this).data('index');
                self._selected = self._galleryImages[index];
                if (!self._selected) {
                    return;
                }
                $modal.find('[data-role=modify-selected-img]').attr('src', self._selected.url);
                $modal.find('#mp-modify-prompt').val('');
                self._showView($modal, 'prompt');
            });

            // Back to the image list.
            $modal.on('click', '[data-role=modify-back-list]', function () {
                self._showView($modal, 'list');
            });

            // Submit the prompt and request a modified image.
            $modal.on('click', '[data-role=modify-submit]', function () {
                self._modify($modal);
            });

            // Discard the result and try a different prompt.
            $modal.on('click', '[data-role=modify-regenerate]', function () {
                self._pendingNew = null;
                self._showView($modal, 'prompt');
            });

            // Confirm: replace the original image with the modified version.
            $modal.on('click', '[data-role=modify-confirm]', function () {
                if (self._selected && self._pendingNew) {
                    self._replaceInGallery(self._selected, self._pendingNew);
                }
                $modal.modal('closeModal');
            });
        },

        /**
         * Switch the visible modal view: 'list', 'prompt' or 'result'.
         *
         * @param {jQuery} $modal
         * @param {String} name
         */
        _showView: function ($modal, name) {
            $modal.find('.mp-modify-view').hide();
            $modal.find('.mp-modify-view-' + name).show();
        },

        /**
         * Read the current product gallery images and render them as a selectable grid.
         *
         * @param {jQuery} $modal
         */
        _renderImageList: function ($modal) {
            var $grid = $modal.find('[data-role=modify-grid]').empty(),
                $empty = $modal.find('[data-role=modify-empty]');

            this._galleryImages = this._collectGalleryImages();

            if (!this._galleryImages.length) {
                $empty.show();
                return;
            }
            $empty.hide();

            $.each(this._galleryImages, function (index, data) {
                var $card = $('<div>', {'class': 'mp-modify-card'});
                $('<img>', {'class': 'mp-modify-thumb', src: data.url, alt: data.label || ''}).appendTo($card);
                $('<button>', {
                    type: 'button',
                    'class': 'action-secondary mp-modify-pick-btn',
                    'data-role': 'modify-pick',
                    'data-index': index
                }).html('<span>' + $.mage.__('Edit with MageAI') + '</span>').appendTo($card);
                $card.appendTo($grid);
            });
        },

        /**
         * Collect the (non-removed, non-video) images currently in the product gallery.
         *
         * @returns {Array}
         */
        _collectGalleryImages: function () {
            var images = [];

            this._getGallery().find('[data-role=image]').each(function () {
                var $el = $(this),
                    data = $el.data('imageData');

                if ($el.hasClass('removed') || !data || !data.file) {
                    return;
                }
                if (data['media_type'] && data['media_type'] !== 'image') {
                    return;
                }
                images.push(data);
            });

            return images;
        },

        /**
         * Resolve the product gallery element.
         *
         * @returns {jQuery}
         */
        _getGallery: function () {
            var $gallery = $(this.options.gallerySelector).first();

            if (!$gallery.length) {
                $gallery = $('#media_gallery_content');
            }

            return $gallery;
        },

        /**
         * Send the selected image and prompt to the controller; on success show the comparison view.
         *
         * @param {jQuery} $modal
         */
        _modify: function ($modal) {
            var self = this,
                prompt = $modal.find('#mp-modify-prompt').val().trim(),
                productName = $('[name="product[name]"]').val() || '',
                attributeData = mageAIModel.collectAttributeData(window.mpMageAIImageAttributes);

            $.ajax({
                url: this.options.modifyImageUrl,
                type: 'POST',
                showLoader: true,
                data: {
                    'form_key': window.FORM_KEY,
                    'custom_prompt': prompt,
                    'image_file': self._selected.file,
                    'product_name': productName,
                    'attribute_data': attributeData
                },
                success: function (response) {
                    if (response && response.error) {
                        alert({
                            title: $.mage.__('Image Edit Error'),
                            content: response.data
                        });
                        return;
                    }

                    if (response && response.file) {
                        self._pendingNew = response;
                        $modal.find('[data-role=modify-original-img]').attr('src', self._selected.url);
                        $modal.find('[data-role=modify-result-img]').attr('src', response.url);
                        self._showView($modal, 'result');
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
         * Replace the original gallery image with the modified one.
         *
         * The modified image is added as a new gallery item (it carries the .tmp flag so Magento
         * moves it to the permanent location on save) and the original is removed. The original's
         * roles (base/small/thumbnail/swatch), position, label and visibility are carried over so
         * the swap is seamless.
         *
         * @param {Object} oldData
         * @param {Object} newData
         */
        _replaceInGallery: function (oldData, newData) {
            var $gallery = this._getGallery(),
                inst = $gallery.data('mage-productGallery'),
                roles = [],
                oldIndex = -1;

            newData.label = oldData.label || newData.name;

            if (inst && inst.options && inst.options.types) {
                $.each(inst.options.types, function (code, type) {
                    if (type && type.value === oldData.file) {
                        roles.push(code);
                    }
                });
                oldIndex = $gallery.find('[data-role=image]').index(inst.findElement(oldData));
            }

            $gallery.trigger('addItem', newData);

            if (oldIndex >= 0) {
                $gallery.trigger('setPosition', {imageData: newData, position: oldIndex});
            }

            $.each(roles, function (i, code) {
                $gallery.trigger('setImageType', {type: code, imageData: newData});
            });

            if (Number(oldData.disabled) === 1) {
                $gallery.trigger('updateVisibility', {disabled: true, imageData: newData});
            }

            $gallery.trigger('updateImageTitle', {imageData: newData});
            $gallery.trigger('removeItem', oldData);
        }
    });

    return $.mage.mageAiImageModify;
});
