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

class OpenAIImageModel implements OptionSourceInterface
{
    /**
     * Options for the OpenAI image model selector
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'gpt-image-1',      'label' => __('GPT Image 1')],
            ['value' => 'gpt-image-1.5',    'label' => __('GPT Image 1.5')],
            ['value' => 'gpt-image-2',      'label' => __('GPT Image 2')],
            ['value' => 'gpt-image-1-mini', 'label' => __('GPT Image 1 Mini')],
        ];
    }
}
