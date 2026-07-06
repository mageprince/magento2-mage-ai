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
use Mageprince\MageAI\Helper\Data as HelperData;

class OverwritePolicyColumn extends Select
{
    /**
     * @param Context $context
     * @param array $data
     */
    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
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
     * Render policy options.
     *
     * @return string
     */
    protected function _toHtml()
    {
        if (!$this->getOptions()) {
            $this->addOption(HelperData::IMAGE_ANALYSIS_POLICY_EMPTY, __('Only if empty'));
            $this->addOption(HelperData::IMAGE_ANALYSIS_POLICY_PLACEHOLDER, __('Empty / placeholder'));
            $this->addOption(HelperData::IMAGE_ANALYSIS_POLICY_MERGE, __('Merge with existing'));
            $this->addOption(HelperData::IMAGE_ANALYSIS_POLICY_MERGE_PROMOTE, __('Merge + promote earlier rows'));
            $this->addOption(HelperData::IMAGE_ANALYSIS_POLICY_REPLACE, __('Always replace'));
        }

        return parent::_toHtml();
    }
}
