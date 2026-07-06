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
    public const IMAGE_ANALYSIS_POLICY_EMPTY = 'empty';
    public const IMAGE_ANALYSIS_POLICY_PLACEHOLDER = 'placeholder';
    public const IMAGE_ANALYSIS_POLICY_MERGE = 'merge';
    public const IMAGE_ANALYSIS_POLICY_MERGE_PROMOTE = 'merge_promote';
    public const IMAGE_ANALYSIS_POLICY_REPLACE = 'replace';

    public const XML_PATH_IS_ENABLED = 'mageai/general/enabled';
    public const XML_PATH_BASELINE_PROMPT = 'mageai/general/baseline_prompt';
    public const XML_PATH_PROVIDER = 'mageai/api/provider';
    public const XML_PATH_API_BASE_URL = 'mageai/api/base_url';
    public const XML_PATH_API_KEY = 'mageai/api/api_secret';
    public const XML_PATH_API_MODEL = 'mageai/api/model';
    public const XML_PATH_API_CUSTOM_MODEL = 'mageai/api/custom_model';
    public const XML_PATH_ANTHROPIC_BASE_URL = 'mageai/api/anthropic_base_url';
    public const XML_PATH_ANTHROPIC_API_KEY = 'mageai/api/anthropic_api_secret';
    public const XML_PATH_ANTHROPIC_MODEL = 'mageai/api/anthropic_model';
    public const XML_PATH_GEMINI_BASE_URL = 'mageai/api/gemini_base_url';
    public const XML_PATH_GEMINI_API_KEY = 'mageai/api/gemini_api_secret';
    public const XML_PATH_GEMINI_MODEL = 'mageai/api/gemini_model';
    public const XML_PATH_OLLAMA_BASE_URL = 'mageai/api/ollama_base_url';
    public const XML_PATH_OLLAMA_MODEL = 'mageai/api/ollama_model';
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
    public const XML_PATH_IMAGE_ANALYSIS_PROMPT = 'mageai/product_image_analysis/prompt';
    public const XML_PATH_IMAGE_ANALYSIS_MAX_TOKENS = 'mageai/product_image_analysis/max_tokens';
    public const XML_PATH_IMAGE_ANALYSIS_TEMPERATURE = 'mageai/product_image_analysis/temperature';
    public const XML_PATH_IMAGE_ANALYSIS_OLLAMA_NUM_CTX = 'mageai/product_image_analysis/ollama_num_ctx';
    public const XML_PATH_IMAGE_ANALYSIS_OLLAMA_THINK = 'mageai/product_image_analysis/ollama_think';
    public const XML_PATH_IMAGE_ANALYSIS_ATTRIBUTES = 'mageai/product_image_analysis/attributes';

    private const DEFAULT_IMAGE_ANALYSIS_OLLAMA_NUM_CTX = 8192;

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
     * @return string  'openai', 'anthropic', 'gemini', or 'ollama'
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
     * Build an OpenAI-compatible endpoint URL from the configured base URL.
     *
     * Merchants commonly enter either "https://host" or "https://host/v1".
     * This method supports both so OpenAI-compatible providers do not get a
     * duplicated /v1 segment.
     *
     * @param string $path Endpoint path, with or without a leading /v1
     * @return string
     */
    public function getOpenAIEndpointUrl(string $path): string
    {
        $baseUrl = rtrim((string) $this->getApiBaseUrl(), '/');
        $path = '/' . ltrim($path, '/');

        if (substr($path, 0, 4) === '/v1/') {
            $pathWithoutVersion = substr($path, 3);
        } else {
            $pathWithoutVersion = $path;
            $path = '/v1' . $path;
        }

        return substr($baseUrl, -3) === '/v1'
            ? $baseUrl . $pathWithoutVersion
            : $baseUrl . $path;
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
        $customModel = trim((string) $this->getConfig(self::XML_PATH_API_CUSTOM_MODEL));
        return $customModel !== '' ? $customModel : $this->getConfig(self::XML_PATH_API_MODEL);
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
     * Get Ollama native API base URL.
     *
     * @return string
     */
    public function getOllamaBaseUrl(): string
    {
        return rtrim((string) ($this->getConfig(self::XML_PATH_OLLAMA_BASE_URL) ?: 'http://localhost:11434'), '/');
    }

    /**
     * Build an Ollama native API endpoint URL.
     *
     * Accepts base URLs with or without a trailing /v1 so a previously configured
     * OpenAI-compatible Ollama endpoint can be reused safely.
     *
     * @param string $path Endpoint path, for example /api/chat
     * @return string
     */
    public function getOllamaEndpointUrl(string $path): string
    {
        $baseUrl = preg_replace('#/v1$#', '', $this->getOllamaBaseUrl());
        return rtrim((string) $baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Get Ollama model ID.
     *
     * @return string
     */
    public function getOllamaModel(): string
    {
        return (string) ($this->getConfig(self::XML_PATH_OLLAMA_MODEL) ?: 'gemma4:12b-it-qat');
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

    /**
     * Get prompt used to analyze an existing product image into catalog metadata.
     *
     * @return string
     */
    public function getProductImageAnalysisPrompt(): string
    {
        return (string) ($this->getConfig(self::XML_PATH_IMAGE_ANALYSIS_PROMPT) ?: '');
    }

    /**
     * Get max tokens for product image metadata analysis.
     *
     * @return int
     */
    public function getProductImageAnalysisMaxTokens(): int
    {
        return (int) ($this->getConfig(self::XML_PATH_IMAGE_ANALYSIS_MAX_TOKENS) ?: 2000);
    }

    /**
     * Get sampling temperature dedicated to product image analysis.
     *
     * Defaults to 0 for deterministic catalog metadata generation.
     *
     * @return float
     */
    public function getProductImageAnalysisTemperature(): float
    {
        $value = $this->getConfig(self::XML_PATH_IMAGE_ANALYSIS_TEMPERATURE);
        return $value === null || $value === '' ? 0.0 : (float) $value;
    }

    /**
     * Get native Ollama context window size for product image metadata analysis.
     *
     * @return int
     */
    public function getProductImageAnalysisOllamaNumCtx(): int
    {
        $value = (int) ($this->getConfig(self::XML_PATH_IMAGE_ANALYSIS_OLLAMA_NUM_CTX)
            ?: self::DEFAULT_IMAGE_ANALYSIS_OLLAMA_NUM_CTX);
        return max(1, $value);
    }

    /**
     * Check whether native Ollama thinking mode is enabled for product image metadata analysis.
     *
     * @return bool
     */
    public function isProductImageAnalysisOllamaThinkEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_IMAGE_ANALYSIS_OLLAMA_THINK);
    }

    /**
     * Get configured image-analysis target attributes and their prompt instructions.
     *
     * @return array<string, string> Attribute code => instruction
     */
    public function getProductImageAnalysisAttributes(): array
    {
        $attributes = [];
        foreach ($this->getProductImageAnalysisAttributeConfig() as $attributeCode => $config) {
            $attributes[$attributeCode] = $config['instruction'];
        }

        return $attributes;
    }

    /**
     * Get configured image-analysis target attributes with update behaviour.
     *
     * @return array<string, array{attribute: string, instruction: string, policy: string, allow_new_options: bool}>
     */
    public function getProductImageAnalysisAttributeConfig(): array
    {
        $configured = $this->normalizeImageAnalysisAttributeRows(
            $this->getConfig(self::XML_PATH_IMAGE_ANALYSIS_ATTRIBUTES)
        );
        $attributes = [];

        foreach ($configured as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string) ($row['attribute'] ?? ''));
            $instruction = trim((string) ($row['instruction'] ?? ''));
            if ($code !== '' && $code !== '__empty' && $instruction !== '') {
                $attributes[$code] = [
                    'attribute' => $code,
                    'instruction' => $instruction,
                    'policy' => $this->normalizeImageAnalysisPolicy(
                        (string) ($row['policy'] ?? $this->getDefaultImageAnalysisPolicy($code))
                    ),
                    'allow_new_options' => $this->normalizeBoolean(
                        $row['allow_new_options'] ?? $this->getDefaultAllowNewOptions($code)
                    ),
                ];
            }
        }

        if (!empty($attributes)) {
            return $attributes;
        }

        return $this->getDefaultImageAnalysisAttributeConfig();
    }

    /**
     * Normalize dynamic image-analysis rows from Magento config storage.
     *
     * ArraySerialized config values can arrive as an array, JSON string, or legacy
     * PHP serialized string depending on Magento version/cache path. Parse all
     * supported shapes before falling back to hardcoded defaults.
     *
     * @param mixed $configured
     * @return array<int|string, mixed>
     */
    private function normalizeImageAnalysisAttributeRows($configured): array
    {
        if (is_array($configured)) {
            return $configured;
        }

        if (!is_string($configured) || trim($configured) === '') {
            return [];
        }

        $decoded = json_decode($configured, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $unserialized = @unserialize($configured, ['allowed_classes' => false]);
        return is_array($unserialized) ? $unserialized : [];
    }

    /**
     * Get default target attributes for image analysis.
     *
     * @return array<string, array{attribute: string, instruction: string, policy: string, allow_new_options: bool}>
     */
    private function getDefaultImageAnalysisAttributeConfig(): array
    {
        $defaults = [
            'name' => [
                'instruction' => 'Generate a short, natural, descriptive product title based on the visible image content. Do not keyword-stuff.',
                'policy' => self::IMAGE_ANALYSIS_POLICY_PLACEHOLDER,
                'allow_new_options' => false,
            ],
            'description' => [
                'instruction' => 'Generate one clear, product-specific ecommerce description paragraph, 80-180 words. Name the visible Biblical subject, people, setting, action, and ministry use when evident. Avoid generic filler such as beautiful image, powerful artwork, inspiring scene, or Christian art unless it adds specific meaning.',
                'policy' => self::IMAGE_ANALYSIS_POLICY_EMPTY,
                'allow_new_options' => false,
            ],
            'meta_title' => [
                'instruction' => 'Generate a concise SEO meta title under 60 characters. Lead with the named Biblical subject, event, person, or symbol; avoid generic words like art, image, beautiful, inspiring, or religious unless needed for clarity.',
                'policy' => self::IMAGE_ANALYSIS_POLICY_EMPTY,
                'allow_new_options' => false,
            ],
            'meta_description' => [
                'instruction' => 'Generate a natural SEO meta description under 155 characters. Describe the specific scene and likely ministry or worship use; avoid generic ecommerce or inspirational filler.',
                'policy' => self::IMAGE_ANALYSIS_POLICY_EMPTY,
                'allow_new_options' => false,
            ],
            'meta_keyword' => [
                'instruction' => 'Generate concise SEO meta keywords from specific visible subjects, named Biblical events, people, places, symbols, and use cases. Return a comma-separated phrase list; avoid colors, counts, emotions, and generic terms unless central to the image.',
                'policy' => self::IMAGE_ANALYSIS_POLICY_EMPTY,
                'allow_new_options' => false,
            ],
            'keywords' => [
                'instruction' => 'Generate 3-8 strongest primary catalog/search keywords as concise subject phrases. Use named Biblical figures, events, places, symbols, and core use cases. Do not use colors, counts, moods, style words, or generic terms like art, image, painting, abstract, modern, good, happy, people, person, scene, or beautiful.',
                'policy' => self::IMAGE_ANALYSIS_POLICY_MERGE_PROMOTE,
                'allow_new_options' => true,
            ],
            'secondary_keywords' => [
                'instruction' => 'Generate 5-12 supporting keywords for secondary subjects, setting, season, scripture theme, ministry use, and visual symbols. Do not repeat primary terms, and avoid bare colors, counts, generic emotions, and generic media words.',
                'policy' => self::IMAGE_ANALYSIS_POLICY_MERGE,
                'allow_new_options' => true,
            ],
            'tertiary_keywords' => [
                'instruction' => 'Generate 5-15 additional long-tail related search terms only when they add specific subject, doctrine, story, worship, or teaching context. Do not repeat stronger primary or secondary terms or add generic filler.',
                'policy' => self::IMAGE_ANALYSIS_POLICY_MERGE,
                'allow_new_options' => true,
            ],
        ];

        $config = [];
        foreach ($defaults as $code => $data) {
            $config[$code] = [
                'attribute' => $code,
                'instruction' => $data['instruction'],
                'policy' => $data['policy'],
                'allow_new_options' => $data['allow_new_options'],
            ];
        }

        return $config;
    }

    /**
     * Normalize an image-analysis update policy.
     *
     * @param string $policy
     * @return string
     */
    private function normalizeImageAnalysisPolicy(string $policy): string
    {
        $valid = [
            self::IMAGE_ANALYSIS_POLICY_EMPTY,
            self::IMAGE_ANALYSIS_POLICY_PLACEHOLDER,
            self::IMAGE_ANALYSIS_POLICY_MERGE,
            self::IMAGE_ANALYSIS_POLICY_MERGE_PROMOTE,
            self::IMAGE_ANALYSIS_POLICY_REPLACE,
        ];

        return in_array($policy, $valid, true) ? $policy : self::IMAGE_ANALYSIS_POLICY_EMPTY;
    }

    /**
     * Get legacy-safe default policy for older rows without the policy column.
     *
     * @param string $attributeCode
     * @return string
     */
    private function getDefaultImageAnalysisPolicy(string $attributeCode): string
    {
        if ($attributeCode === 'name') {
            return self::IMAGE_ANALYSIS_POLICY_PLACEHOLDER;
        }
        if ($attributeCode === 'keywords') {
            return self::IMAGE_ANALYSIS_POLICY_MERGE_PROMOTE;
        }
        if (in_array($attributeCode, ['secondary_keywords', 'tertiary_keywords'], true)) {
            return self::IMAGE_ANALYSIS_POLICY_MERGE;
        }

        return self::IMAGE_ANALYSIS_POLICY_EMPTY;
    }

    /**
     * Get legacy-safe default option creation behaviour.
     *
     * @param string $attributeCode
     * @return bool
     */
    private function getDefaultAllowNewOptions(string $attributeCode): bool
    {
        return in_array($attributeCode, ['keywords', 'secondary_keywords', 'tertiary_keywords'], true);
    }

    /**
     * Normalize Magento config truthy/falsey values.
     *
     * @param mixed $value
     * @return bool
     */
    private function normalizeBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
