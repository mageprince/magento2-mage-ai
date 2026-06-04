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

use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Mageprince\MageAI\Helper\Data as HelperData;

class ImageGeneration
{
    /**
     * JPEG quality (0-100) for GPT Image output — 80 is visually lossless for product photos
     */
    private const OPENAI_JPEG_QUALITY = 80;

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
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var MediaConfig
     */
    protected $mediaConfig;

    /**
     * @var CurlFactory
     */
    protected $curlFactory;

    /**
     * @param Curl $curl
     * @param Json $json
     * @param HelperData $helper
     * @param Filesystem $filesystem
     * @param MediaConfig $mediaConfig
     * @param CurlFactory $curlFactory
     */
    public function __construct(
        Curl $curl,
        Json $json,
        HelperData $helper,
        Filesystem $filesystem,
        MediaConfig $mediaConfig,
        CurlFactory $curlFactory
    ) {
        $this->curl = $curl;
        $this->json = $json;
        $this->helper = $helper;
        $this->filesystem = $filesystem;
        $this->mediaConfig = $mediaConfig;
        $this->curlFactory = $curlFactory;
    }

    /**
     * Generate an image for the given prompt using the configured AI provider
     *
     * Returns file data compatible with the Magento product gallery upload format.
     *
     * @param string $prompt
     * @return array{file: string, url: string, name: string, size: int, type: string}
     * @throws QueryException
     */
    public function generate(string $prompt): array
    {
        switch ($this->helper->getProvider()) {
            case 'openai':
                return $this->generateWithOpenAI($prompt);
            case 'gemini':
                return $this->generateWithGemini($prompt);
            default:
                throw new QueryException(__(
                    'Image generation is not supported by the Anthropic provider. Please switch to OpenAI or Gemini in MageAI configuration.'
                ));
        }
    }

    /**
     * Generate image via OpenAI
     *
     * @param string $prompt
     * @return array
     * @throws QueryException
     */
    protected function generateWithOpenAI(string $prompt): array
    {
        $apiKey = $this->helper->getApiSecret();
        if (!$apiKey) {
            throw new QueryException(__('OpenAI API Key not found. Please check configuration.'));
        }

        $this->curl->setHeaders([
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ]);

        // quality is the biggest speed lever: 'low' generates several times faster than 'high'.
        $payload = $this->json->serialize([
            'model'              => $this->helper->getImageModel(),
            'prompt'             => $prompt,
            'n'                  => 1,
            'size'               => $this->helper->getImageSize(),
            'quality'            => $this->helper->getImageQuality(),
            'output_format'      => 'jpeg',
            'output_compression' => self::OPENAI_JPEG_QUALITY,
        ]);

        $this->curl->post($this->helper->getApiBaseUrl() . '/v1/images/generations', $payload);

        $status = $this->curl->getStatus();
        if ($status === 401) {
            throw new QueryException(__('Unauthorized response. Please check OpenAI API key.'));
        }
        if ($status >= 500) {
            throw new QueryException(__('OpenAI server error.'));
        }

        $response = $this->json->unserialize($this->curl->getBody());

        if (isset($response['error'])) {
            throw new QueryException(__($response['error']['message'] ?? 'Unknown OpenAI API error.'));
        }
        if (empty($response['data'][0])) {
            throw new QueryException(__('No image data returned from OpenAI API.'));
        }

        $item = $response['data'][0];

        // GPT Image models return base64 (b64_json); url is kept as a fallback for compatible endpoints.
        if (!empty($item['b64_json'])) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $imageData = base64_decode($item['b64_json']);
            if ($imageData === false) {
                throw new QueryException(__('Failed to decode base64 image from OpenAI response.'));
            }
            return $this->persistImageData($imageData, 'image/jpeg', 'jpg');
        }

        if (!empty($item['url'])) {
            $imageData = $this->downloadImageFromUrl($item['url']);
            return $this->persistImageData($imageData, 'image/jpeg', 'jpg');
        }

        throw new QueryException(__('No image URL or base64 data found in OpenAI response.'));
    }

    /**
     * Generate image via Google Gemini Imagen API
     *
     * @param string $prompt
     * @return array
     * @throws QueryException
     */
    protected function generateWithGemini(string $prompt): array
    {
        $apiKey = $this->helper->getGeminiApiSecret();
        if (!$apiKey) {
            throw new QueryException(__('Gemini API Key not found. Please check configuration.'));
        }

        $this->curl->setHeaders([
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $apiKey,
        ]);

        // Gemini image models (gemini-*-image) use the standard generateContent endpoint
        $payload = $this->json->serialize([
            'contents' => [
                ['parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
            ],
        ]);

        $model = $this->helper->getGeminiImageModel();
        $url = $this->helper->getGeminiBaseUrl() . '/v1beta/models/' . $model . ':generateContent';
        $this->curl->post($url, $payload);

        $status = $this->curl->getStatus();
        if ($status === 401 || $status === 403) {
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
            throw new QueryException(__('No image data returned from Gemini API. Ensure the selected model supports image generation.'));
        }

        $mimeType = $imagePart['mimeType'] ?? $imagePart['mime_type'] ?? 'image/png';
        $ext = $mimeType === 'image/jpeg' ? 'jpg' : 'png';

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $imageData = base64_decode($imagePart['data']);
        if ($imageData === false) {
            throw new QueryException(__('Failed to decode image data returned by Gemini.'));
        }

        return $this->persistImageData($imageData, $mimeType, $ext);
    }

    /**
     * Download image binary from a URL
     *
     * @param string $url
     * @return string
     * @throws QueryException
     */
    protected function downloadImageFromUrl(string $url): string
    {
        // Use a fresh Curl instance so the API request's auth headers do not leak into this download
        /** @var Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->setTimeout(60);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, true);

        try {
            $curl->get($url);
        } catch (\Exception $e) {
            throw new QueryException(__('Failed to download generated image: %1', $e->getMessage()));
        }

        $data = $curl->getBody();
        if ($curl->getStatus() >= 400 || $data === '') {
            throw new QueryException(__('Failed to download generated image (HTTP %1).', $curl->getStatus()));
        }

        return $data;
    }

    /**
     * Save image binary to the product media temp directory and return gallery-compatible file data
     *
     * The format mirrors what Magento\Catalog\Controller\Adminhtml\Product\Gallery\Upload returns.
     *
     * The file is stored using Magento's standard two-level dispersion path (/m/a/filename.jpg).
     * This is required: the get.php on-the-fly resizer (MediaStorage\App\Media::getOriginalImage)
     * resolves the original image from a cache URL by taking the last three path segments,
     * so any non-dispersed path breaks frontend cache generation and shows the placeholder.
     *
     * @param string $imageData  Raw binary content
     * @param string $mimeType
     * @param string $ext
     * @return array
     * @throws QueryException
     */
    protected function persistImageData(string $imageData, string $mimeType, string $ext): array
    {
        $fileName = 'mageai_' . uniqid('', true) . '.' . $ext;

        // Standard Magento dispersion: /m/a/ for "mageai_..." filenames
        $dispersionPath = \Magento\Framework\File\Uploader::getDispersionPath($fileName);
        $fileRelativeToTmp = $dispersionPath . '/' . $fileName; // /m/a/mageai_xxx.jpg

        try {
            $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);

            // Save under catalog/product/tmp/m/a/ so Magento moves it to the permanent
            // location (catalog/product/m/a/) when the product is saved.
            $tmpBase = $this->mediaConfig->getBaseTmpMediaPath(); // catalog/product/tmp
            $mediaDirectory->create($tmpBase . $dispersionPath);
            $mediaDirectory->writeFile($tmpBase . $fileRelativeToTmp, $imageData);
        } catch (\Exception $e) {
            throw new QueryException(__('Failed to save generated image: %1', $e->getMessage()));
        }

        $url = $this->mediaConfig->getTmpMediaUrl($fileRelativeToTmp);

        return [
            'name' => $fileName,
            'size' => strlen($imageData),
            'type' => $mimeType,
            'url'  => $url,
            // Appending .tmp signals Magento to move this file to the permanent directory on product save
            'file' => $fileRelativeToTmp . '.tmp',
        ];
    }
}
