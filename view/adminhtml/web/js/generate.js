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
                var sku = $("input[name='product[sku]']").val();
                var type = 'full';
                if($(this).attr('id') == mageAIModel.options.shortDescriptionFieldIdentifier) {
                    type = 'short';
                }

                mageAIModel.generateContent(sku, type, false)
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
            $(document).on('click', mageAIModel.options.advancedGenerateBtnSelector, function (event) {
                mageAIModel.clickAdvancedGenerateButton(this);
            });
        }
    });

    return $.mage.mageAigenerateWidget;
});
