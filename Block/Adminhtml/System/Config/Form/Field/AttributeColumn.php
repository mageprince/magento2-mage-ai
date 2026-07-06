<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */

namespace Mageprince\MageAI\Block\Adminhtml\System\Config\Form\Field;

use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;
use Mageprince\MageAI\Model\Config\Source\ImageAnalysisAttributes;

class AttributeColumn extends Select
{
    /**
     * @var ImageAnalysisAttributes
     */
    protected $attributes;

    /**
     * @param Context $context
     * @param ImageAnalysisAttributes $attributes
     * @param array $data
     */
    public function __construct(Context $context, ImageAnalysisAttributes $attributes, array $data = [])
    {
        parent::__construct($context, $data);
        $this->attributes = $attributes;
    }

    /**
     * Set input name for dynamic row rendering.
     *
     * @param string $value
     * @return $this
     */
    public function setInputName($value)
    {
        return $this->setName($value);
    }

    /**
     * Set input id for dynamic row rendering.
     *
     * @param string $value
     * @return $this
     */
    public function setInputId($value)
    {
        return $this->setId($value);
    }

    /**
     * Render attribute options.
     *
     * @return string
     */
    protected function _toHtml()
    {
        if (!$this->getOptions()) {
            foreach ($this->attributes->toOptionArray() as $option) {
                if (($option['value'] ?? '') === '') {
                    continue;
                }
                $this->addOption($option['value'], $option['label']);
            }
        }

        return parent::_toHtml();
    }
}
