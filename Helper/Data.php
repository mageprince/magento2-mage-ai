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

namespace Mageprince\MageAI\Helper;

use Magento\Framework\App\Helper\AbstractHelper;

class Data extends AbstractHelper
{
    /**
     * Config XML paths
     */
    public const XML_PATH_IS_ENABLED = 'mageai/general/enabled';
    public const XML_PATH_API_BASE_URL = 'mageai/api/base_url';
    public const XML_PATH_API_KEY = 'mageai/api/api_secret';
    public const XML_PATH_API_MODEL = 'mageai/api/model';
    public const XML_PATH_PRODUCT_ATTRIBUTE = 'mageai/product_description/attribute';
    public const XML_PATH_DESCRIPTION_PROMPT = 'mageai/product_description/description_prompt';
    public const XML_PATH_DESCRIPTION_WORD_COUNT = 'mageai/product_description/description_words_count';
    public const XML_PATH_SHORT_SHORT_DESCRIPTION_PROMPT = 'mageai/product_description/short_description_prompt';
    public const XML_PATH_SHORT_DESCRIPTION_WORD_COUNT = 'mageai/product_description/short_description_words_count';

    /**
     * Get config value
     *
     * @param string $path
     * @return mixed
     */
    public function getConfig($path)
    {
        return $this->scopeConfig->getValue($path);
    }

    /**
     * Check is extension is enabled
     *
     * @return bool
     */
    public function isEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_IS_ENABLED);
    }

    /**
     * Get API base url
     *
     * @return string
     */
    public function getApiBaseUrl()
    {
        return $this->getConfig(self::XML_PATH_API_BASE_URL);
    }

    /**
     * Get API secret
     *
     * @return string
     */
    public function getApiSecret()
    {
        return $this->getConfig(self::XML_PATH_API_KEY);
    }

    /**
     * Get description prompt
     *
     * @return string
     */
    public function getDescriptionPrompt()
    {
        return $this->getConfig(self::XML_PATH_DESCRIPTION_PROMPT);
    }

    /**
     * Get number of description words
     *
     * @return int
     */
    public function getDescriptionWordCount()
    {
        return (int) $this->getConfig(self::XML_PATH_DESCRIPTION_WORD_COUNT);
    }

    /**
     * Get short description prompt
     *
     * @return string
     */
    public function getShortDescriptionPrompt()
    {
        return $this->getConfig(self::XML_PATH_SHORT_SHORT_DESCRIPTION_PROMPT);
    }

    /**
     * Get number of short description words
     *
     * @return int
     */
    public function getShortDescriptionWordCount()
    {
        return (int) $this->getConfig(self::XML_PATH_SHORT_DESCRIPTION_WORD_COUNT);
    }

    /**
     * Get max token
     *
     * @param string $type
     * @return float
     */
    public function getMaxToken($type)
    {
        if ($type == 'short') {
            $wordCount = $this->getShortDescriptionWordCount();
        } else {
            $wordCount = $this->getDescriptionWordCount();
        }
        return round($wordCount * 1.5);
    }

    /**
     * Get api model
     *
     * @return string
     */
    public function getModel()
    {
        return $this->getConfig(self::XML_PATH_API_MODEL);
    }

    /**
     * Get product attribute code
     *
     * @return mixed
     */
    public function getProductAttribute()
    {
        return $this->getConfig(self::XML_PATH_PRODUCT_ATTRIBUTE);
    }
}
