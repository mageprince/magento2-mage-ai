define([
    'jquery',
    'Mageprince_MageAI/js/model/mage-ai'
], function ($, mageAIModel) {
    'use strict';

    $.widget('mage.mageAigenerateWidget', {

        /**
         * Initializes event listeners for generating product descriptions.
         */
        _create: function () {
            // Listen for click events on the standard generate button
            $(document).on('click', mageAIModel.options.generateBtnSelector, function () {
                var currentTarget = this;
                var type = 'full';
                if ($(this).attr('id') == mageAIModel.options.shortDescriptionFieldIdentifier) {
                    type = 'short';
                }

                var attributeData = mageAIModel.collectAttributeData();
                mageAIModel.generateContent(attributeData, type, false)
                    .done(function (content) {
                        if (content) {
                            mageAIModel.updateDescription(content, currentTarget);
                        }
                    })
                    .fail(function (error) {
                        console.error('Error generating content:', error);
                    });
            });

            // Listen for click events on the advanced generate button
            $(document).on('click', mageAIModel.options.advancedGenerateBtnSelector, function () {
                mageAIModel.clickAdvancedGenerateButton(this);
            });

            // Listen for click events on image metadata generation button
            $(document).on('click', mageAIModel.options.imageMetadataBtnSelector, function () {
                mageAIModel.generateImageMetadata();
            });

            // Listen for click events on image metadata queue button
            $(document).on('click', mageAIModel.options.queueImageMetadataBtnSelector, function () {
                mageAIModel.queueImageMetadata();
            });
        }
    });

    return $.mage.mageAigenerateWidget;
});
