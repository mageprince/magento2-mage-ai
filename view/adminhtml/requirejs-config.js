var config = {
    map: {
        '*': {
            'mageAiGenerate': 'Mageprince_MageAI/js/generate',
            'Magento_PageBuilder/template/form/element/html-code.html':
                'Mageprince_MageAI/template/html-code.html'
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
