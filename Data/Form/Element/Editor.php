<?php
/**
 * Mageprince
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the mageprince.com license that is
 * available through the world-wide-web at this URL:
 * https://mageprince.com/end-user-license-agreement
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 * @copyright   Copyright (c) Mageprince (https://mageprince.com/)
 * @license     https://mageprince.com/end-user-license-agreement
 */

namespace Mageprince\MageAI\Data\Form\Element;

use Magento\Framework\Data\Form\Element\CollectionFactory;
use Magento\Framework\Data\Form\Element\Factory;
use Magento\Framework\Escaper;
use Magento\Framework\Math\Random;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Mageprince\MageAI\Helper\Data as HelperData;

class Editor extends \Magento\Framework\Data\Form\Element\Editor
{
    public const ALLOWED_FIELDS_HTML_ID = [
        'product_form_description',
        'product_form_short_description'
    ];

    /**
     * @var HelperData
     */
    protected $helper;

    /**
     * Editor constructor.
     *
     * @param Factory $factoryElement
     * @param CollectionFactory $factoryCollection
     * @param Escaper $escaper
     * @param HelperData $helper
     * @param array $data
     * @param \Magento\Framework\Serialize\Serializer\Json|null $serializer
     * @param Random|null $random
     * @param SecureHtmlRenderer|null $secureRenderer
     */
    public function __construct(
        Factory $factoryElement,
        CollectionFactory $factoryCollection,
        Escaper $escaper,
        HelperData $helper,
        $data = [],
        \Magento\Framework\Serialize\Serializer\Json $serializer = null,
        ?Random $random = null,
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
        $this->helper = $helper;
        parent::__construct(
            $factoryElement,
            $factoryCollection,
            $escaper,
            $data,
            $serializer,
            $random,
            $secureRenderer
        );
    }

    /**
     * Return HTML button to toggling WYSIWYG
     *
     * @param bool $visible
     * @return string
     */
    protected function _getToggleButtonHtml($visible = true)
    {
        $html = parent::_getToggleButtonHtml($visible);
        $isEnabled = $this->helper->isEnabled();
        if ($isEnabled && in_array($this->getHtmlId(), self::ALLOWED_FIELDS_HTML_ID)) {
            $html .= $this->_getButtonHtml(
                [
                    'title' => $this->translate('Generate content with MageAI'),
                    'class' => 'generate-mageai-short-content',
                    'style' => $visible ? '' : 'display:none',
                    'id' => $this->getHtmlId() . '_mageai',
                ]
            );
        }
        return $html;
    }
}
