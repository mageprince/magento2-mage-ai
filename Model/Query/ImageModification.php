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

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Mageprince\MageAI\Helper\Data as HelperData;

/**
 * Modifies an existing product image from a text prompt using the configured AI provider.
 *
 * OpenAI uses the image-edits endpoint (multipart upload of the original image); Gemini sends the
 * original image inline alongside the prompt to generateContent. Anthropic is not supported.
 */
class ImageModification
{
    /**
     * JPEG quality (0-100) for GPT Image output — 80 is visually lossless for product photos
     */
    private const OPENAI_JPEG_QUALITY = 80;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var HelperData
     */
    protected $helper;

    /**
     * @var ImageStorage
     */
    protected $imageStorage;

    /**
     * @var CurlFactory
     */
    protected $curlFactory;

    /**
     * @param Json $json
     * @param HelperData $helper
     * @param ImageStorage $imageStorage
     * @param CurlFactory $curlFactory
     */
    public function __construct(
        Json $json,
        HelperData $helper,
        ImageStorage $imageStorage,
        CurlFactory $curlFactory
    ) {
        $this->json = $json;
        $this->helper = $helper;
        $this->imageStorage = $imageStorage;
        $this->curlFactory = $curlFactory;
    }

    /**
     * Modify an existing product image using the configured AI provider.
     *
     * Returns file data compatible with the Magento product gallery upload format.
     *
     * @param string $prompt
     * @param string $sourceFile Gallery imageData.file value of the image to modify
     * @return array{file: string, url: string, name: string, size: int, type: string}
     * @throws QueryException
     */
    public function modify(string $prompt, string $sourceFile): array
    {
        $original = $this->imageStorage->readOriginal($sourceFile);

        switch ($this->helper->getProvider()) {
            case 'openai':
                return $this->modifyWithOpenAI($prompt, $original);
            case 'gemini':
                return $this->modifyWithGemini($prompt, $original);
            default:
                throw new QueryException(__(
                    'Image modification is not supported by the Anthropic provider. Please switch to OpenAI or Gemini in MageAI configuration.'
                ));
        }
    }

    /**
     * Modify the image via the OpenAI image edits endpoint (multipart/form-data).
     *
     * @param string $prompt
     * @param array $original ['data' => binary, 'mimeType' => string, 'ext' => string]
     * @return array
     * @throws QueryException
     */
    protected function modifyWithOpenAI(string $prompt, array $original): array
    {
        $apiKey = $this->helper->getApiSecret();
        if (!$apiKey) {
            throw new QueryException(__('OpenAI API Key not found. Please check configuration.'));
        }

        // The edits endpoint needs a real file handle for the multipart upload.
        $temp = $this->imageStorage->writeTempFile($original['data'], $original['ext']);

        try {
            $fields = [
                'model'              => $this->helper->getImageModel(),
                'prompt'             => $prompt,
                'n'                  => '1',
                'size'               => $this->helper->getImageSize(),
                'quality'            => $this->helper->getImageQuality(),
                'output_format'      => 'jpeg',
                'output_compression' => (string) self::OPENAI_JPEG_QUALITY,
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                'image'              => new \CURLFile($temp['absolutePath'], $original['mimeType'], 'image.' . $original['ext']),
            ];

            /** @var Curl $curl */
            $curl = $this->curlFactory->create();
            $curl->setTimeout(180);
            // Only the auth header — let libcurl set the multipart Content-Type (with boundary).
            $curl->setHeaders(['Authorization' => 'Bearer ' . $apiKey]);
            // Passing an array (with the CURLFile) as POSTFIELDS forces a multipart request.
            // setOption is applied after Curl::post's default body, so it overrides the url-encoded params.
            $curl->setOption(CURLOPT_POSTFIELDS, $fields);
            $curl->post($this->helper->getApiBaseUrl() . '/v1/images/edits', '');

            $status = $curl->getStatus();
            if ($status === 401) {
                throw new QueryException(__('Unauthorized response. Please check OpenAI API key.'));
            }
            if ($status >= 500) {
                throw new QueryException(__('OpenAI server error.'));
            }

            $response = $this->json->unserialize($curl->getBody());
        } finally {
            $this->imageStorage->removeTempFile($temp['path']);
        }

        if (isset($response['error'])) {
            throw new QueryException(__($response['error']['message'] ?? 'Unknown OpenAI API error.'));
        }
        if (empty($response['data'][0]['b64_json'])) {
            throw new QueryException(__('No modified image data returned from OpenAI API.'));
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $imageData = base64_decode($response['data'][0]['b64_json']);
        if ($imageData === false) {
            throw new QueryException(__('Failed to decode base64 image from OpenAI response.'));
        }

        return $this->imageStorage->persist($imageData, 'image/jpeg', 'jpg');
    }

    /**
     * Modify the image via Google Gemini — the original image is sent inline with the prompt.
     *
     * @param string $prompt
     * @param array $original ['data' => binary, 'mimeType' => string, 'ext' => string]
     * @return array
     * @throws QueryException
     */
    protected function modifyWithGemini(string $prompt, array $original): array
    {
        $apiKey = $this->helper->getGeminiApiSecret();
        if (!$apiKey) {
            throw new QueryException(__('Gemini API Key not found. Please check configuration.'));
        }

        $curl = $this->curlFactory->create();
        $curl->setTimeout(180);
        $curl->setHeaders([
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $apiKey,
        ]);

        // The image part carries the original; the text part carries the edit instructions.
        $payload = $this->json->serialize([
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                                'mime_type' => $original['mimeType'],
                                'data'      => base64_encode($original['data']),
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
            ],
        ]);

        $model = $this->helper->getGeminiImageModel();
        $url = $this->helper->getGeminiBaseUrl() . '/v1beta/models/' . $model . ':generateContent';
        $curl->post($url, $payload);

        $status = $curl->getStatus();
        if ($status === 401 || $status === 403) {
            throw new QueryException(__('Unauthorized response. Please check Gemini API key.'));
        }
        if ($status >= 500) {
            throw new QueryException(__('Gemini server error.'));
        }

        $response = $this->json->unserialize($curl->getBody());

        if (isset($response['error'])) {
            throw new QueryException(__($response['error']['message'] ?? 'Unknown Gemini API error.'));
        }

        $finishReason = $response['candidates'][0]['finishReason'] ?? '';
        if ($finishReason === 'SAFETY' || $finishReason === 'IMAGE_SAFETY') {
            throw new QueryException(__('Gemini blocked the image due to safety filters. Try adjusting the prompt.'));
        }

        // Find the image part — the response mixes text and image parts.
        // REST responses use camelCase (inlineData); check snake_case too for proxy compatibility.
        $imagePart = null;
        foreach ($response['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (!empty($part['inlineData']['data'])) {
                $imagePart = $part['inlineData'];
                break;
            }
            if (!empty($part['inline_data']['data'])) {
                $imagePart = $part['inline_data'];
                break;
            }
        }

        if ($imagePart === null) {
            throw new QueryException(__('No modified image data returned from Gemini API. Ensure the selected model supports image editing.'));
        }

        $mimeType = $imagePart['mimeType'] ?? $imagePart['mime_type'] ?? 'image/png';
        $ext = $mimeType === 'image/jpeg' ? 'jpg' : 'png';

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $imageData = base64_decode($imagePart['data']);
        if ($imageData === false) {
            throw new QueryException(__('Failed to decode image data returned by Gemini.'));
        }

        return $this->imageStorage->persist($imageData, $mimeType, $ext);
    }
}
