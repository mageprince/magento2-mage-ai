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

class AnthropicModel implements OptionSourceInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'claude-opus-4-8', 'label' => 'claude-opus-4-8'],
            ['value' => 'claude-sonnet-4-6', 'label' => 'claude-sonnet-4-6'],
            ['value' => 'claude-haiku-4-5-20251001', 'label' => 'claude-haiku-4-5'],
            ['value' => 'claude-3-5-sonnet-20241022', 'label' => 'claude-3-5-sonnet-20241022'],
            ['value' => 'claude-3-5-haiku-20241022', 'label' => 'claude-3-5-haiku-20241022'],
            ['value' => 'claude-3-opus-20240229', 'label' => 'claude-3-opus-20240229'],
            ['value' => 'claude-3-sonnet-20240229', 'label' => 'claude-3-sonnet-20240229'],
            ['value' => 'claude-3-haiku-20240307', 'label' => 'claude-3-haiku-20240307'],
        ];
    }
}
