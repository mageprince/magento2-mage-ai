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
    public const XML_PATH_IS_ENABLED = 'mageai/general/enabled';
    public const XML_PATH_BASELINE_PROMPT = 'mageai/general/baseline_prompt';
    public const XML_PATH_PROVIDER = 'mageai/api/provider';
    public const XML_PATH_API_BASE_URL = 'mageai/api/base_url';
    public const XML_PATH_API_KEY = 'mageai/api/api_secret';
    public const XML_PATH_API_MODEL = 'mageai/api/model';
    public const XML_PATH_ANTHROPIC_BASE_URL = 'mageai/api/anthropic_base_url';
    public const XML_PATH_ANTHROPIC_API_KEY = 'mageai/api/anthropic_api_secret';
    public const XML_PATH_ANTHROPIC_MODEL = 'mageai/api/anthropic_model';
    public const XML_PATH_GEMINI_BASE_URL = 'mageai/api/gemini_base_url';
    public const XML_PATH_GEMINI_API_KEY = 'mageai/api/gemini_api_secret';
    public const XML_PATH_GEMINI_MODEL = 'mageai/api/gemini_model';
    public const XML_PATH_PRODUCT_ATTRIBUTE = 'mageai/product_description/attribute';
    public const XML_PATH_TEMPERATURE = 'mageai/product_description/temperature';
    public const XML_PATH_DESCRIPTION_PROMPT = 'mageai/product_description/description_prompt';
    public const XML_PATH_DESCRIPTION_MAX_TOKENS = 'mageai/product_description/description_max_tokens';
    public const XML_PATH_SHORT_SHORT_DESCRIPTION_PROMPT = 'mageai/product_description/short_description_prompt';
    public const XML_PATH_SHORT_DESCRIPTION_MAX_TOKENS = 'mageai/product_description/short_description_max_tokens';
    public const XML_PATH_IMAGE_DEFAULT_PROMPT = 'mageai/image_generation/default_prompt';
    public const XML_PATH_IMAGE_MODIFY_DEFAULT_PROMPT = 'mageai/image_generation/modify_default_prompt';
    public const XML_PATH_IMAGE_ATTRIBUTE = 'mageai/image_generation/attribute';
    public const XML_PATH_IMAGE_MODEL = 'mageai/image_generation/openai_image_model';
    public const XML_PATH_GPT_IMAGE_SIZE = 'mageai/image_generation/gpt_image_size';
    public const XML_PATH_GPT_IMAGE_QUALITY = 'mageai/image_generation/gpt_image_quality';
    public const XML_PATH_GEMINI_IMAGE_MODEL = 'mageai/image_generation/gemini_image_model';

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
     * Check if extension is enabled
     *
     * @return bool
     */
    public function isEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_IS_ENABLED);
    }

    /**
     * Get the global baseline (system) prompt applied to every text generation request
     *
     * Merchant-configured brand voice / compliance / SEO instructions. Empty by default.
     *
     * @return string
     */
    public function getBaselinePrompt(): string
    {
        return trim((string) $this->getConfig(self::XML_PATH_BASELINE_PROMPT));
    }

    /**
     * Get selected AI provider
     *
     * @return string  'openai' or 'anthropic'
     */
    public function getProvider()
    {
        return (string) $this->getConfig(self::XML_PATH_PROVIDER) ?: 'openai';
    }

    /**
     * Get OpenAI API base URL
     *
     * @return string
     */
    public function getApiBaseUrl()
    {
        return $this->getConfig(self::XML_PATH_API_BASE_URL);
    }

    /**
     * Get OpenAI API secret
     *
     * @return string
     */
    public function getApiSecret()
    {
        return $this->getConfig(self::XML_PATH_API_KEY);
    }

    /**
     * Get OpenAI model
     *
     * @return string
     */
    public function getModel()
    {
        return $this->getConfig(self::XML_PATH_API_MODEL);
    }

    /**
     * Get Anthropic API base URL
     *
     * @return string
     */
    public function getAnthropicBaseUrl()
    {
        return $this->getConfig(self::XML_PATH_ANTHROPIC_BASE_URL);
    }

    /**
     * Get Anthropic API secret
     *
     * @return string
     */
    public function getAnthropicApiSecret()
    {
        return $this->getConfig(self::XML_PATH_ANTHROPIC_API_KEY);
    }

    /**
     * Get Anthropic model
     *
     * @return string
     */
    public function getAnthropicModel()
    {
        return $this->getConfig(self::XML_PATH_ANTHROPIC_MODEL);
    }

    /**
     * Get Gemini API base URL
     *
     * @return string
     */
    public function getGeminiBaseUrl()
    {
        return $this->getConfig(self::XML_PATH_GEMINI_BASE_URL);
    }

    /**
     * Get Gemini API secret
     *
     * @return string
     */
    public function getGeminiApiSecret()
    {
        return $this->getConfig(self::XML_PATH_GEMINI_API_KEY);
    }

    /**
     * Get Gemini model
     *
     * @return string
     */
    public function getGeminiModel()
    {
        return $this->getConfig(self::XML_PATH_GEMINI_MODEL);
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
     * Get short description prompt
     *
     * @return string
     */
    public function getShortDescriptionPrompt()
    {
        return $this->getConfig(self::XML_PATH_SHORT_SHORT_DESCRIPTION_PROMPT);
    }

    /**
     * Get sampling temperature
     *
     * @return float
     */
    public function getTemperature(): float
    {
        return (float) ($this->getConfig(self::XML_PATH_TEMPERATURE) ?? 0.5);
    }

    /**
     * Get max tokens for a description type
     *
     * @param string $type  'short' or 'full'
     * @return int
     */
    public function getMaxTokens(string $type): int
    {
        $path = $type === 'short'
            ? self::XML_PATH_SHORT_DESCRIPTION_MAX_TOKENS
            : self::XML_PATH_DESCRIPTION_MAX_TOKENS;
        return (int) ($this->getConfig($path) ?: 2048);
    }

    /**
     * Get selected product attribute codes as an array
     *
     * @return string[]
     */
    public function getProductAttributes(): array
    {
        $value = (string) $this->getConfig(self::XML_PATH_PRODUCT_ATTRIBUTE);
        return array_filter(array_map('trim', explode(',', $value)));
    }

    /**
     * Get default image generation prompt template
     *
     * @return string
     */
    public function getImageDefaultPrompt(): string
    {
        return (string) ($this->getConfig(self::XML_PATH_IMAGE_DEFAULT_PROMPT) ?: '');
    }

    /**
     * Get default image modification prompt template
     *
     * @return string
     */
    public function getImageModifyDefaultPrompt(): string
    {
        return (string) ($this->getConfig(self::XML_PATH_IMAGE_MODIFY_DEFAULT_PROMPT) ?: '');
    }

    /**
     * Get selected product attribute codes for image generation as an array
     *
     * @return string[]
     */
    public function getImageAttributes(): array
    {
        $value = (string) $this->getConfig(self::XML_PATH_IMAGE_ATTRIBUTE);
        return array_filter(array_map('trim', explode(',', $value)));
    }

    /**
     * Get OpenAI image generation model (dall-e-3 / dall-e-2)
     *
     * @return string
     */
    public function getImageModel(): string
    {
        return (string) ($this->getConfig(self::XML_PATH_IMAGE_MODEL) ?: 'gpt-image-2');
    }

    /**
     * Get the configured image size (all GPT Image models share the same size set)
     *
     * @return string
     */
    public function getImageSize(): string
    {
        return (string) ($this->getConfig(self::XML_PATH_GPT_IMAGE_SIZE) ?: '1024x1024');
    }

    /**
     * Get the configured OpenAI image quality (low / medium / high / auto)
     *
     * Lower quality generates significantly faster.
     *
     * @return string
     */
    public function getImageQuality(): string
    {
        return (string) ($this->getConfig(self::XML_PATH_GPT_IMAGE_QUALITY) ?: 'medium');
    }

    /**
     * Get Gemini Imagen model
     *
     * @return string
     */
    public function getGeminiImageModel(): string
    {
        return (string) ($this->getConfig(self::XML_PATH_GEMINI_IMAGE_MODEL) ?: 'gemini-2.5-flash-image');
    }
}
