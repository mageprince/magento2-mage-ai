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
// phpcs:disable Generic.Files.LineLength

namespace Mageprince\MageAI\Model\Query;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Mageprince\MageAI\Helper\Data as HelperData;

class Completions
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const ANTHROPIC_DEFAULT_MAX_TOKENS = 2048;
    private const GEMINI_DEFAULT_MAX_TOKENS = 2048;
    private const SYSTEM_PROMPT = 'You are a helpful assistant. Provide only the main generated content without any greetings, introductions, or explanations. Never wrap output in markdown code blocks or backticks.';

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var HelperData
     */
    protected $helper;

    /**
     * @var EavConfig
     */
    protected $eavConfig;

    /**
     * @param Curl $curl
     * @param Json $json
     * @param HelperData $helper
     * @param EavConfig $eavConfig
     */
    public function __construct(Curl $curl, Json $json, HelperData $helper, EavConfig $eavConfig)
    {
        $this->curl = $curl;
        $this->helper = $helper;
        $this->json = $json;
        $this->eavConfig = $eavConfig;
    }

    /**
     * Generate product description content via the configured AI provider
     *
     * @param ProductInterface $product
     * @param string $type  'short' or 'full'
     * @return string
     * @throws QueryException
     */
    public function generateProductDescription(ProductInterface $product, string $type): string
    {
        $prompt = $this->buildProductDescriptionPrompt($product, $type);
        return $this->generate($prompt, $this->helper->getMaxTokens($type));
    }

    /**
     * Generate content from a free-form custom prompt
     *
     * @param string $prompt
     * @return string
     * @throws QueryException
     */
    public function generateCustomContent(string $prompt): string
    {
        return $this->generate($prompt);
    }

    /**
     * Generate description from raw attribute data collected from the product form.
     * Works for both new (unsaved) and existing products.
     *
     * @param array  $data  ['attributeCode' => 'displayValue', ...]
     * @param string $type  'short' or 'full'
     * @return string
     * @throws QueryException
     */
    public function generateProductDescriptionFromData(array $data, string $type): string
    {
        $prompt = $this->buildProductDescriptionFromData($data, $type);
        return $this->generate($prompt, $this->helper->getMaxTokens($type));
    }

    /**
     * Build the prompt string from raw form data using {{ product.name }} / {{ product.attributes }} variables.
     *
     * @param array  $data
     * @param string $type
     * @return string
     */
    protected function buildProductDescriptionFromData(array $data, string $type): string
    {
        $template = $type === 'short'
            ? $this->helper->getShortDescriptionPrompt()
            : $this->helper->getDescriptionPrompt();

        $productName = $data['name'] ?? '';

        $parts = [];
        foreach ($data as $code => $value) {
            if ($value !== null && $value !== '') {
                $label = $this->resolveAttributeLabel($code);
                $parts[] = $label . ': ' . $value;
            }
        }

        $prompt = str_replace('{{ product.name }}', $productName, $template);
        $prompt = str_replace('{{ product.attributes }}', implode(', ', $parts), $prompt);

        return $prompt;
    }

    /**
     * Resolve a catalog_product attribute code to its frontend label.
     * Falls back to a humanised version of the code if the attribute is not found.
     *
     * @param string $code
     * @return string
     */
    protected function resolveAttributeLabel(string $code): string
    {
        try {
            $attribute = $this->eavConfig->getAttribute('catalog_product', $code);
            if ($attribute && $attribute->getAttributeId()) {
                return $attribute->getDefaultFrontendLabel() ?: $this->humanizeCode($code);
            }
        } catch (\Exception $e) {
            // fall through to default
        }
        return $this->humanizeCode($code);
    }

    /**
     * Convert an attribute code to a human-readable label (e.g. short_description → Short Description).
     *
     * @param string $code
     * @return string
     */
    private function humanizeCode(string $code): string
    {
        return ucwords(str_replace('_', ' ', $code));
    }

    /**
     * Dispatch to the configured provider
     *
     * @param string $prompt
     * @param int|false $maxToken
     * @return string
     * @throws QueryException
     */
    protected function generate(string $prompt, $maxToken = false): string
    {
        switch ($this->helper->getProvider()) {
            case 'anthropic':
                return $this->makeAnthropicRequest($this->getAnthropicPayload($prompt, $maxToken));
            case 'gemini':
                return $this->makeGeminiRequest($this->getGeminiPayload($prompt, $maxToken));
            default:
                return $this->makeOpenAIRequest($this->getOpenAIPayload($prompt, $maxToken));
        }
    }

    /**
     * Build the prompt string for product description generation
     *
     * @param ProductInterface $product
     * @param string $type
     * @return string
     */
    protected function buildProductDescriptionPrompt(ProductInterface $product, string $type): string
    {
        $template = $type === 'short'
            ? $this->helper->getShortDescriptionPrompt()
            : $this->helper->getDescriptionPrompt();

        $prompt = str_replace('{{ product.name }}', (string) $product->getName(), $template);
        $prompt = str_replace('{{ product.attributes }}', $this->buildAttributesText($product), $prompt);

        return $prompt;
    }

    /**
     * Build a comma-separated "Label: Value" string from all selected product attributes
     *
     * @param ProductInterface $product
     * @return string
     */
    protected function buildAttributesText(ProductInterface $product): string
    {
        $parts = [];
        foreach ($this->helper->getProductAttributes() as $code) {
            /** @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attr */
            $attr = $product->getResource()->getAttribute($code);
            if (!$attr) {
                continue;
            }
            $label = $attr->getDefaultFrontendLabel();
            $value = $attr->getFrontend()->getValue($product);
            if ($label && $value !== null && $value !== '' && $value !== false) {
                $parts[] = $label . ': ' . $value;
            }
        }
        return implode(', ', $parts);
    }

    // -------------------------------------------------------------------------
    // OpenAI
    // -------------------------------------------------------------------------

    /**
     * Set OpenAI request headers
     *
     * @return void
     * @throws QueryException
     */
    protected function setOpenAIHeaders(): void
    {
        $token = $this->helper->getApiSecret();
        if (!$token) {
            throw new QueryException(__('OpenAI API Key not found. Please check configuration.'));
        }
        $this->curl->setHeaders([
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    /**
     * Build OpenAI API payload
     *
     * @param string $prompt
     * @param int|false $maxToken
     * @return string
     */
    protected function getOpenAIPayload(string $prompt, $maxToken = false): string
    {
        $model = $this->helper->getModel();
        $payload = [
            'model'             => $model,
            'n'                 => 1,
            'temperature'       => $this->helper->getTemperature(),
            'frequency_penalty' => 0,
            'presence_penalty'  => 0,
        ];

        if ($maxToken) {
            $payload['max_tokens'] = $maxToken;
        }

        if (strpos($model, 'gpt') !== false) {
            $payload['messages'] = [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user',   'content' => $prompt],
            ];
        } else {
            $payload['prompt'] = $prompt;
        }

        return $this->json->serialize($payload);
    }

    /**
     * Execute OpenAI API request and return generated text
     *
     * @param string $payload
     * @return string
     * @throws QueryException
     */
    protected function makeOpenAIRequest(string $payload): string
    {
        $this->setOpenAIHeaders();
        $model = $this->helper->getModel();
        $endpoint = strpos($model, 'gpt') !== false ? '/v1/chat/completions' : '/v1/completions';
        $this->curl->post($this->helper->getApiBaseUrl() . $endpoint, $payload);
        return $this->validateOpenAIResponse();
    }

    /**
     * Parse and validate OpenAI API response
     *
     * @return string
     * @throws QueryException
     */
    protected function validateOpenAIResponse(): string
    {
        $status = $this->curl->getStatus();

        if ($status == 401) {
            throw new QueryException(__('Unauthorized response. Please check OpenAI API key.'));
        }
        if ($status >= 500) {
            throw new QueryException(__('OpenAI server error.'));
        }

        $response = $this->json->unserialize($this->curl->getBody());

        if (isset($response['error'])) {
            throw new QueryException(__($response['error']['message'] ?? 'Unknown OpenAI API error.'));
        }
        if (!isset($response['choices'])) {
            throw new QueryException(__('No results found from OpenAI API response.'));
        }

        $content = $response['choices'][0]['text'] ?? $response['choices'][0]['message']['content'] ?? '';
        return trim($content);
    }

    // -------------------------------------------------------------------------
    // Anthropic (Claude)
    // -------------------------------------------------------------------------

    /**
     * Set Anthropic request headers
     *
     * @return void
     * @throws QueryException
     */
    protected function setAnthropicHeaders(): void
    {
        $token = $this->helper->getAnthropicApiSecret();
        if (!$token) {
            throw new QueryException(__('Anthropic API Key not found. Please check configuration.'));
        }
        $this->curl->setHeaders([
            'Content-Type'      => 'application/json',
            'x-api-key'         => $token,
            'anthropic-version' => self::ANTHROPIC_VERSION,
        ]);
    }

    /**
     * Build Anthropic Messages API payload
     *
     * @param string $prompt
     * @param int|false $maxToken
     * @return string
     */
    protected function getAnthropicPayload(string $prompt, $maxToken = false): string
    {
        $payload = [
            'model'       => $this->helper->getAnthropicModel(),
            'max_tokens'  => $maxToken ?: self::ANTHROPIC_DEFAULT_MAX_TOKENS,
            'temperature' => min(1.0, $this->helper->getTemperature()),
            'system'      => self::SYSTEM_PROMPT,
            'messages'    => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        return $this->json->serialize($payload);
    }

    /**
     * Execute Anthropic Messages API request and return generated text
     *
     * @param string $payload
     * @return string
     * @throws QueryException
     */
    protected function makeAnthropicRequest(string $payload): string
    {
        $this->setAnthropicHeaders();
        $this->curl->post($this->helper->getAnthropicBaseUrl() . '/v1/messages', $payload);
        return $this->validateAnthropicResponse();
    }

    /**
     * Parse and validate Anthropic API response
     *
     * @return string
     * @throws QueryException
     */
    protected function validateAnthropicResponse(): string
    {
        $status = $this->curl->getStatus();

        if ($status == 401) {
            throw new QueryException(__('Unauthorized response. Please check Anthropic API key.'));
        }
        if ($status >= 500) {
            throw new QueryException(__('Anthropic server error.'));
        }

        $response = $this->json->unserialize($this->curl->getBody());

        if (isset($response['error'])) {
            throw new QueryException(__($response['error']['message'] ?? 'Unknown Anthropic API error.'));
        }
        if (empty($response['content'][0]['text'])) {
            throw new QueryException(__('No results found from Anthropic API response.'));
        }

        return $this->stripCodeFences($response['content'][0]['text']);
    }

    // -------------------------------------------------------------------------
    // Google Gemini
    // -------------------------------------------------------------------------

    /**
     * Set Gemini request headers
     *
     * @return void
     * @throws QueryException
     */
    protected function setGeminiHeaders(): void
    {
        $token = $this->helper->getGeminiApiSecret();
        if (!$token) {
            throw new QueryException(__('Gemini API Key not found. Please check configuration.'));
        }
        $this->curl->setHeaders([
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $token,
        ]);
    }

    /**
     * Build Gemini generateContent API payload
     *
     * @param string $prompt
     * @param int|false $maxToken
     * @return string
     */
    protected function getGeminiPayload(string $prompt, $maxToken = false): string
    {
        $payload = [
            'system_instruction' => [
                'parts' => [['text' => self::SYSTEM_PROMPT]],
            ],
            'contents' => [
                ['parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature'     => $this->helper->getTemperature(),
                'maxOutputTokens' => $maxToken ?: self::GEMINI_DEFAULT_MAX_TOKENS,
            ],
        ];
        return $this->json->serialize($payload);
    }

    /**
     * Execute Gemini generateContent API request and return generated text
     *
     * @param string $payload
     * @return string
     * @throws QueryException
     */
    protected function makeGeminiRequest(string $payload): string
    {
        $this->setGeminiHeaders();
        $model = $this->helper->getGeminiModel();
        $url = $this->helper->getGeminiBaseUrl() . '/v1beta/models/' . $model . ':generateContent';
        $this->curl->post($url, $payload);
        return $this->validateGeminiResponse();
    }

    /**
     * Parse and validate Gemini API response
     *
     * @return string
     * @throws QueryException
     */
    protected function validateGeminiResponse(): string
    {
        $status = $this->curl->getStatus();

        if ($status == 401 || $status == 403) {
            throw new QueryException(__('Unauthorized response. Please check Gemini API key.'));
        }
        if ($status >= 500) {
            throw new QueryException(__('Gemini server error.'));
        }

        $response = $this->json->unserialize($this->curl->getBody());

        if (isset($response['error'])) {
            throw new QueryException(__($response['error']['message'] ?? 'Unknown Gemini API error.'));
        }

        $finishReason = $response['candidates'][0]['finishReason'] ?? '';
        if ($finishReason === 'SAFETY') {
            throw new QueryException(__('Gemini blocked the response due to safety filters. Try adjusting the prompt.'));
        }

        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($text === '') {
            throw new QueryException(__('No results found from Gemini API response.'));
        }

        return $this->stripCodeFences($text);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Strip markdown code fences that some models add despite being told not to.
     * Handles ```html, ```xml, ``` and similar variants.
     *
     * @param string $content
     * @return string
     */
    private function stripCodeFences(string $content): string
    {
        $content = trim($content);
        $content = preg_replace('/^```[a-z]*\r?\n?/i', '', $content);
        $content = preg_replace('/\r?\n?```\s*$/i', '', $content);
        return trim($content);
    }
}
