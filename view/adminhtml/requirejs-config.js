var config = {
    map: {
        '*': {
            'mageAiGenerate': 'Mageprince_MageAI/js/generate',
            'mageAiImageGenerate': 'Mageprince_MageAI/js/image-generate',
            'mageAiImageModify': 'Mageprince_MageAI/js/image-modify'
        }
    },
    config: {
        mixins: {
            'Magento_PageBuilder/js/form/element/html-code': {
                'Mageprince_MageAI/js/html-code-mixin': true
            }
        }
    }
};
