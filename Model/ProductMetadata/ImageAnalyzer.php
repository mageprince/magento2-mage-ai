<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */
// phpcs:disable Generic.Files.LineLength

namespace Mageprince\MageAI\Model\ProductMetadata;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Mageprince\MageAI\Helper\Data as HelperData;
use Mageprince\MageAI\Model\Query\QueryException;

class ImageAnalyzer
{
    private const SYSTEM_PROMPT = 'You analyze Christian art product images for ecommerce catalog metadata. Return only valid JSON. Be specific to the visible Biblical subject, event, people, setting, symbols, and ministry use. Avoid generic filler, generic emotions, bare colors, counts, and media words unless central to the image. Do not use markdown, explanations, or code fences.';
    private const REQUEST_TIMEOUT = 900;

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
     * @var ImageReader
     */
    protected $imageReader;

    /**
     * @var ProductAttributeRepositoryInterface
     */
    protected $attributeRepository;

    /**
     * @param Curl $curl
     * @param Json $json
     * @param HelperData $helper
     * @param ImageReader $imageReader
     * @param ProductAttributeRepositoryInterface $attributeRepository
     */
    public function __construct(
        Curl $curl,
        Json $json,
        HelperData $helper,
        ImageReader $imageReader,
        ProductAttributeRepositoryInterface $attributeRepository
    ) {
        $this->curl = $curl;
        $this->json = $json;
        $this->helper = $helper;
        $this->imageReader = $imageReader;
        $this->attributeRepository = $attributeRepository;
    }

    /**
     * Analyze a product image and return generated configured product attributes.
     *
     * @param ProductInterface $product
     * @param callable|null $debugLogger Receives request/response debug labels and raw content.
     * @param string $previousProductName Context from the product ID immediately before the current product.
     * @return array<string, mixed>
     * @throws QueryException
     */
    public function analyze(ProductInterface $product, ?callable $debugLogger = null, string $previousProductName = ''): array
    {
        $image = $this->imageReader->read($product);
        if ($this->helper->getProvider() === 'ollama') {
            $payload = $this->buildOllamaPayload($product, $image['data'], $previousProductName);
            $endpoint = $this->helper->getOllamaEndpointUrl('/api/chat');
            $this->writeDebug($debugLogger, 'Ollama request', "POST " . $endpoint . "\nContent-Type: application/json\n\n" . $payload);

            $this->setOllamaHeaders();
            $this->curl->post($endpoint, $payload);
            $this->writeDebug($debugLogger, 'Ollama response', "HTTP " . $this->curl->getStatus() . "\n\n" . $this->curl->getBody());

            return $this->validateOllamaResponse();
        }

        $payload = $this->buildPayload($product, $image['data'], $image['mimeType'], $previousProductName);
        $endpoint = $this->helper->getOpenAIEndpointUrl('/v1/chat/completions');
        $this->writeDebug($debugLogger, 'OpenAI-compatible request', "POST " . $endpoint . "\nContent-Type: application/json\n\n" . $payload);

        $this->setHeaders();
        $this->curl->post($endpoint, $payload);
        $this->writeDebug($debugLogger, 'OpenAI-compatible response', "HTTP " . $this->curl->getStatus() . "\n\n" . $this->curl->getBody());

        return $this->validateResponse();
    }

    /**
     * Emit debug content for callers that opted into raw AI transport output.
     *
     * @param callable|null $debugLogger
     * @param string $label
     * @param string $content
     * @return void
     */
    private function writeDebug(?callable $debugLogger, string $label, string $content): void
    {
        if ($debugLogger === null) {
            return;
        }

        $debugLogger($label, $content);
    }

    /**
     * Build OpenAI-compatible chat completions payload with inline image data.
     *
     * @param ProductInterface $product
     * @param string $imageData
     * @param string $mimeType
     * @param string $previousProductName
     * @return string
     */
    private function buildPayload(ProductInterface $product, string $imageData, string $mimeType, string $previousProductName = ''): string
    {
        $targetAttributes = $this->helper->getProductImageAnalysisAttributeConfig();
        $prompt = $this->buildPrompt($product, $targetAttributes, $previousProductName);

        return $this->json->serialize([
            'model' => $this->helper->getModel(),
            'temperature' => $this->helper->getProductImageAnalysisTemperature(),
            'max_tokens' => $this->helper->getProductImageAnalysisMaxTokens(),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'product_image_analysis',
                    'strict' => true,
                    'schema' => $this->buildResponseSchema($targetAttributes),
                ],
            ],
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:' . $mimeType . ';base64,' . base64_encode($imageData),
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Build Ollama native /api/chat payload with schema-constrained output.
     *
     * @param ProductInterface $product
     * @param string $imageData
     * @param string $previousProductName
     * @return string
     */
    private function buildOllamaPayload(ProductInterface $product, string $imageData, string $previousProductName = ''): string
    {
        $targetAttributes = $this->helper->getProductImageAnalysisAttributeConfig();
        $schema = $this->buildResponseSchema($targetAttributes);
        $prompt = $this->buildPrompt($product, $targetAttributes, $previousProductName);
        $prompt = $this->appendResponseSchemaToPrompt($prompt, $schema);

        return $this->json->serialize([
            'model' => $this->helper->getOllamaModel(),
            'think' => $this->helper->isProductImageAnalysisOllamaThinkEnabled(),
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                [
                    'role' => 'user',
                    'content' => $prompt,
                    'images' => [base64_encode($imageData)],
                ],
            ],
            'format' => $schema,
            'options' => $this->buildOllamaOptions(),
            'stream' => false,
        ]);
    }

    /**
     * Build Ollama generation options for schema-constrained image metadata.
     *
     * @return array<string, int|float>
     */
    private function buildOllamaOptions(): array
    {
        return [
            'temperature' => $this->helper->getProductImageAnalysisTemperature(),
            'num_ctx' => $this->helper->getProductImageAnalysisOllamaNumCtx(),
            'num_predict' => $this->helper->getProductImageAnalysisMaxTokens(),
        ];
    }

    /**
     * Add the response schema to the prompt for Ollama structured-output grounding.
     *
     * @param string $prompt
     * @param array<string, mixed> $schema
     * @return string
     */
    private function appendResponseSchemaToPrompt(string $prompt, array $schema): string
    {
        return $prompt . "\n\nReturn the final JSON object immediately. Do not include reasoning, hidden thinking, markdown, or explanatory text. The JSON object must match this JSON Schema exactly:\n"
            . $this->json->serialize($schema);
    }

    /**
     * Set OpenAI-compatible request headers.
     *
     * @return void
     * @throws QueryException
     */
    private function setHeaders(): void
    {
        $token = $this->helper->getApiSecret();
        if (!$token) {
            throw new QueryException(__('OpenAI-compatible API key not found. Please check MageAI configuration.'));
        }

        $this->curl->setTimeout(self::REQUEST_TIMEOUT);
        $this->curl->setHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    /**
     * Set Ollama native request headers.
     *
     * @return void
     */
    private function setOllamaHeaders(): void
    {
        $this->curl->setTimeout(self::REQUEST_TIMEOUT);
        $this->curl->setHeaders([
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Parse and validate the AI response.
     *
     * @return array<string, mixed>
     * @throws QueryException
     */
    private function validateResponse(): array
    {
        $status = $this->curl->getStatus();
        if ($status === 401 || $status === 403) {
            throw new QueryException(__('Unauthorized response. Please check OpenAI-compatible API credentials.'));
        }
        if ($status >= 400) {
            throw new QueryException(__('OpenAI-compatible endpoint returned HTTP %1: %2', $status, $this->curl->getBody()));
        }

        $response = $this->json->unserialize($this->curl->getBody());
        if (isset($response['error'])) {
            throw new QueryException(__($response['error']['message'] ?? 'Unknown OpenAI-compatible API error.'));
        }

        $content = $response['choices'][0]['message']['content'] ?? $response['choices'][0]['text'] ?? '';
        if (!is_string($content) || trim($content) === '') {
            throw new QueryException(__('No image metadata content was returned by the OpenAI-compatible endpoint.'));
        }

        $data = $this->parseJsonObject($content);
        return isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : $data;
    }

    /**
     * Parse and validate an Ollama native /api/chat response.
     *
     * @return array<string, mixed>
     * @throws QueryException
     */
    private function validateOllamaResponse(): array
    {
        $status = $this->curl->getStatus();
        if ($status >= 400) {
            throw new QueryException(__('Ollama endpoint returned HTTP %1: %2', $status, $this->curl->getBody()));
        }

        $response = $this->json->unserialize($this->curl->getBody());
        if (isset($response['error'])) {
            throw new QueryException(__($response['error']));
        }

        $content = $response['message']['content'] ?? '';
        if (!is_string($content) || trim($content) === '') {
            $doneReason = $response['done_reason'] ?? '';
            $thinking = $response['message']['thinking'] ?? '';
            if ($doneReason === 'length' && is_string($thinking) && trim($thinking) !== '') {
                throw new QueryException(__('Ollama returned thinking output but no JSON content before reaching the generation limit. Disable Ollama Thinking Mode for image metadata, raise Image Analysis Max Tokens, or use a non-thinking vision model.'));
            }
            if ($doneReason === 'length') {
                throw new QueryException(__('Ollama reached the generation limit before returning image metadata JSON. Raise Image Analysis Max Tokens or use a model that produces shorter structured output.'));
            }
            if (is_string($thinking) && trim($thinking) !== '') {
                throw new QueryException(__('Ollama returned thinking output but no image metadata JSON content. Use a non-thinking vision model or a model/runtime that supports think=false.'));
            }
            throw new QueryException(__('No image metadata content was returned by the Ollama native endpoint.'));
        }

        $data = $this->parseJsonObject($content);
        return isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : $data;
    }

    /**
     * Build full user prompt including target instructions and existing values.
     *
     * @param ProductInterface $product
     * @param array<string, array{attribute: string, instruction: string, policy: string, allow_new_options: bool}> $targetAttributes
     * @param string $previousProductName
     * @return string
     */
    private function buildPrompt(ProductInterface $product, array $targetAttributes, string $previousProductName = ''): string
    {
        $lines = [];
        $lines[] = $this->helper->getProductImageAnalysisPrompt();
        $lines[] = '';
        $lines[] = 'Target attributes to generate:';

        foreach ($targetAttributes as $code => $config) {
            $attribute = $this->getAttribute($code);
            $optionInstruction = $this->getOptionPromptInstruction($attribute, (bool) $config['allow_new_options']);
            $lines[] = sprintf(
                '- %s (%s, input: %s, policy: %s): %s%s',
                $code,
                $attribute ? (string) $attribute->getDefaultFrontendLabel() : $code,
                $attribute ? (string) $attribute->getFrontendInput() : 'text',
                $config['policy'],
                $config['instruction'],
                $optionInstruction
            );
        }

        $lines[] = '';
        $lines[] = 'Quality rules:';
        $lines[] = '- Prefer specific Biblical subjects, named figures, events, places, symbols, doctrine, season, and ministry/worship use cases.';
        $lines[] = '- Primary keywords must be the main searchable subjects or story terms, not colors, numbers, moods, style labels, or generic product/media words.';
        $lines[] = '- Avoid generic keyword labels such as art, image, picture, painting, scene, abstract, modern, good, beautiful, happy, people, person, blue, red, green, purple, yellow, orange, black, white, one, two, three, four, 2nd, or second.';
        $lines[] = '- Descriptions must identify what is visibly happening and should not use vague filler like beautiful image, powerful artwork, inspiring scene, or perfect for any use.';
        $lines[] = '- Do not invent people, locations, objects, scripture references, or doctrine that are not visible or strongly supported by the existing product context.';
        $lines[] = '- If the existing title names the Biblical event or subject, preserve that meaning and use it to improve missing SEO fields.';
        $lines[] = '- Return every requested schema key. Use JSON strings for scalar fields and JSON arrays for keyword fields.';
        $lines[] = '- Do not return blank name, description, meta_title, meta_description, or meta_keyword values when the image or current product context provides enough evidence for a safe value.';
        $lines[] = '- Keep meta_title under 60 characters when possible and meta_description under 155 characters when possible.';
        $lines[] = '- Do not repeat the same keyword across primary, secondary, and tertiary keyword fields.';
        $lines[] = '- Return empty arrays for keyword fields when no specific non-generic terms can be justified.';

        $normalizedPreviousProductName = preg_replace('/[\p{C}\s]+/u', ' ', $previousProductName);
        $previousProductName = is_string($normalizedPreviousProductName) ? trim($normalizedPreviousProductName) : '';
        if ($previousProductName !== '') {
            $lines[] = '';
            $lines[] = 'Adjacent product ID context:';
            $lines[] = '- previous product name from current product ID minus 1: ' . $previousProductName;
            $lines[] = '- Use this only as weak sequence context for related images. Do not copy names, people, events, or doctrine unless the current image visibly supports them.';
        }

        $lines[] = '';
        $lines[] = 'Existing product context. Use these values when helpful, especially when generating one blank field from other populated fields:';
        $lines[] = '- sku: ' . (string) $product->getSku();

        foreach ($targetAttributes as $code => $config) {
            $lines[] = sprintf('- current %s: %s', $code, $this->getProductAttributeText($product, $code));
        }

        return implode("\n", $lines);
    }

    /**
     * Build JSON schema for configured target attributes.
     *
     * @param array<string, array{attribute: string, instruction: string, policy: string, allow_new_options: bool}> $targetAttributes
     * @return array<string, mixed>
     */
    private function buildResponseSchema(array $targetAttributes): array
    {
        $properties = [];
        $required = [];

        foreach ($targetAttributes as $code => $config) {
            $attribute = $this->getAttribute($code);
            $input = $attribute ? (string) $attribute->getFrontendInput() : 'text';
            $enum = $attribute && !$config['allow_new_options'] ? $this->getOptionLabels($attribute) : [];
            $required[] = $code;

            if ($input === 'multiselect') {
                $items = ['type' => 'string'];
                if (!empty($enum) && count($enum) <= 200) {
                    $items['enum'] = $enum;
                }
                $properties[$code] = [
                    'type' => 'array',
                    'description' => $config['instruction'],
                    'items' => $items,
                    'uniqueItems' => true,
                    'maxItems' => $this->getMaxItemsForAttribute($code),
                ];
                continue;
            }

            $properties[$code] = [
                'type' => 'string',
                'description' => $config['instruction'],
            ];
            $maxLength = $this->getMaxLengthForAttribute($code);
            if ($maxLength) {
                $properties[$code]['maxLength'] = $maxLength;
            }
            if ($input === 'select' && !empty($enum) && count($enum) <= 200) {
                $properties[$code]['enum'] = $enum;
            }
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * Get a safe maximum number of generated list items for known keyword attributes.
     *
     * @param string $code
     * @return int
     */
    private function getMaxItemsForAttribute(string $code): int
    {
        switch ($code) {
            case 'keywords':
                return 8;
            case 'secondary_keywords':
                return 12;
            case 'tertiary_keywords':
                return 15;
            default:
                return 20;
        }
    }

    /**
     * Get schema max length constraints for common SEO scalar attributes.
     *
     * @param string $code
     * @return int|null
     */
    private function getMaxLengthForAttribute(string $code): ?int
    {
        switch ($code) {
            case 'meta_title':
                return 70;
            case 'meta_description':
                return 180;
            case 'meta_keyword':
                return 255;
            default:
                return null;
        }
    }

    /**
     * Extract a JSON object from a model response.
     *
     * @param string $content
     * @return array<string, mixed>
     * @throws QueryException
     */
    private function parseJsonObject(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```[a-z]*\r?\n?/i', '', $content);
        $content = preg_replace('/\r?\n?```\s*$/i', '', $content);

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $content = substr($content, $start, $end - $start + 1);
        }

        try {
            $data = $this->json->unserialize($content);
        } catch (\Exception $e) {
            throw new QueryException(__('Image metadata response was not valid JSON: %1', $e->getMessage()));
        }

        if (!is_array($data)) {
            throw new QueryException(__('Image metadata response was not a JSON object.'));
        }

        return $data;
    }

    /**
     * Get attribute metadata by code.
     *
     * @param string $code
     * @return \Magento\Catalog\Api\Data\ProductAttributeInterface|null
     */
    private function getAttribute(string $code)
    {
        try {
            return $this->attributeRepository->get($code);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Build option-specific prompt instructions for select and multiselect fields.
     *
     * @param mixed $attribute
     * @param bool $allowNewOptions
     * @return string
     */
    private function getOptionPromptInstruction($attribute, bool $allowNewOptions): string
    {
        if (!$attribute) {
            return '';
        }

        $input = (string) $attribute->getFrontendInput();
        if ($input !== 'select' && $input !== 'multiselect') {
            return '';
        }

        if ($allowNewOptions) {
            return ' You may return concise new option labels when existing options do not cover the image. Use specific subject phrases only; avoid generic colors, counts, moods, media words, and style labels.';
        }

        $labels = $this->getOptionLabels($attribute);
        if (empty($labels)) {
            return ' Choose only an existing option label; do not invent a new option.';
        }

        return ' Choose only from these existing option labels: ' . implode(', ', array_slice($labels, 0, 200)) . '.';
    }

    /**
     * Get non-empty option labels for an attribute.
     *
     * @param mixed $attribute
     * @return string[]
     */
    private function getOptionLabels($attribute): array
    {
        $input = (string) $attribute->getFrontendInput();
        if ($input !== 'select' && $input !== 'multiselect') {
            return [];
        }

        $labels = [];
        try {
            foreach ($attribute->getSource()->getAllOptions(false, false) as $option) {
                $label = trim((string) ($option['label'] ?? ''));
                if ($label !== '') {
                    $labels[$label] = $label;
                }
            }
        } catch (\Exception $e) {
            return [];
        }

        natcasesort($labels);
        return array_values($labels);
    }

    /**
     * Render a product attribute value as prompt context.
     *
     * @param ProductInterface $product
     * @param string $code
     * @return string
     */
    private function getProductAttributeText(ProductInterface $product, string $code): string
    {
        $attribute = $this->getAttribute($code);
        if (!$attribute) {
            return trim(strip_tags((string) $product->getData($code)));
        }

        try {
            $value = $attribute->getFrontend()->getValue($product);
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            return trim(strip_tags((string) $value));
        } catch (\Exception $e) {
            return trim(strip_tags((string) $product->getData($code)));
        }
    }
}
