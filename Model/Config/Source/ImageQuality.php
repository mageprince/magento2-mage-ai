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

namespace Mageprince\MageAI\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ImageQuality implements OptionSourceInterface
{
    /**
     * Options for the OpenAI image quality selector
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'low',    'label' => __('Low (Fastest)')],
            ['value' => 'medium', 'label' => __('Medium (Balanced)')],
            ['value' => 'high',   'label' => __('High (Best quality, slowest)')],
            ['value' => 'auto',   'label' => __('Auto (Model decides)')],
        ];
    }
}
