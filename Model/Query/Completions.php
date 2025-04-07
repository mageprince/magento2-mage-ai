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

namespace Mageprince\MageAI\Model\Query;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Mageprince\MageAI\Helper\Data as HelperData;

class Completions
{
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
     * @param Curl $curl
     * @param Json $json
     * @param HelperData $helper
     */
    public function __construct(
        Curl $curl,
        Json $json,
        HelperData $helper
    ) {
        $this->curl = $curl;
        $this->helper = $helper;
        $this->json = $json;
    }

    /**
     * Get curl object
     *
     * @return Curl
     */
    public function getCurlClient()
    {
        return $this->curl;
    }

    /**
     * Call OpenAI API request
     *
     * @param ProductInterface $product
     * @param string $type
     * @return string
     * @throws QueryException
     */
    public function makeRequest($product, $type)
    {
        $this->setHeaders();
        $baseUrl = $this->helper->getApiBaseUrl();
        $model = $this->helper->getModel();
        if (strpos($model, 'gpt') !== false) {
            $endpoint = '/v1/chat/completions';
        } else {
            $endpoint = '/v1/completions';
        }

        $this->getCurlClient()->post(
            $baseUrl . $endpoint,
            $this->getPayload($product, $type)
        );
        return $this->validateResponse();
    }

    /**
     * Set API header
     *
     * @return void
     * @throws QueryException
     */
    protected function setHeaders()
    {
        $token = $this->helper->getApiSecret();
        if (!$token) {
            throw new QueryException(__('API Secret not found. Please check configuration'));
        }
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token
        ];
        $this->getCurlClient()->setHeaders($headers);
    }

    /**
     * Get API payload
     *
     * @param ProductInterface $product
     * @param string $type
     * @return string
     */
    protected function getPayload($product, $type)
    {
        $model = $this->helper->getModel();
        $payload =  [
            'model' => $model,
            'n' => 1,
            'max_tokens' => $this->helper->getMaxToken($type),
            'temperature' => 0.5,
            'frequency_penalty' => 0,
            'presence_penalty' => 0
        ];

        $prompt = $this->getPrompt($product, $type);
        if (strpos($model, 'gpt') !== false) {
            $payload['messages'] = [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => $prompt]
            ];
        } else {
            $payload['prompt'] = $prompt;
        }

        return $this->json->serialize($payload);
    }

    /**
     * Get prompt
     *
     * @param ProductInterface $product
     * @param string $type
     * @return string
     */
    public function getPrompt($product, $type)
    {
        if ($type == 'short') {
            $prompt = $this->helper->getShortDescriptionPrompt();
            $wordCount = $this->helper->getShortDescriptionWordCount();
        } else {
            $prompt = $this->helper->getDescriptionPrompt();
            $wordCount = $this->helper->getDescriptionWordCount();
        }

        $attribute = $this->helper->getProductAttribute();
        /** @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute $productAttribute */
        $productAttribute = $product->getResource()->getAttribute($attribute);
        $attributeLabel = $productAttribute->getDefaultFrontendLabel();
        $attributeValue = $productAttribute->getFrontend()->getValue($product);

        return sprintf(
            $prompt,
            $wordCount,
            $attributeLabel,
            $attributeValue
        );
    }

    /**
     * Verify API response
     *
     * @return string string
     * @throws QueryException
     */
    public function validateResponse()
    {
        if ($this->getCurlClient()->getStatus() == 401) {
            throw new QueryException(__('Unauthorized response. Please check token.'));
        }

        if ($this->getCurlClient()->getStatus() >= 500) {
            throw new QueryException(__('Server error'));
        }

        $response = $this->json->unserialize($this->getCurlClient()->getBody());

        if (isset($response['error'])) {
            throw new QueryException(__($response['error']['message'] ?? 'Unknown Error'));
        }

        if (!isset($response['choices'])) {
            throw new QueryException(__('No results found from API response'));
        }

        $content = '';
        if (isset($response['choices'][0]['text'])) {
            $content = $response['choices'][0]['text'];
        } elseif (isset($response['choices'][0]['message']['content'])) {
            $content = $response['choices'][0]['message']['content'];
        }

        return trim($content);
    }
}
