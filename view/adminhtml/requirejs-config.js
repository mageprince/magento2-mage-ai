var config = {
    map: {
        '*': {
            'mageAiGenerate': 'Mageprince_MageAI/js/generate'
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
