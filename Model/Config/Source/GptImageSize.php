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

class GptImageSize implements OptionSourceInterface
{
    /**
     * Sizes supported by the gpt-image-1 model
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'auto',      'label' => __('Auto (model decides)')],
            ['value' => '1024x1024', 'label' => __('1024×1024 — Square')],
            ['value' => '1536x1024', 'label' => __('1536×1024 — Landscape')],
            ['value' => '1024x1536', 'label' => __('1024×1536 — Portrait')],
        ];
    }
}
