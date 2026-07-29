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
use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeOptionManagementInterface;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Eav\Api\Data\AttributeOptionLabelInterfaceFactory;
use Magento\Eav\Model\Entity\Attribute\OptionLabel;
use Magento\Eav\Model\Entity\Attribute\Source\TableFactory;
use Magento\Framework\Exception\InputException;
use Mageprince\MageAI\Helper\Data as HelperData;

class MetadataApplier
{
    private const KEYWORD_ATTRIBUTE_CODES = [];
    private const MAX_GENERATED_KEYWORD_LABELS = [];
    private const GENERIC_KEYWORD_LABELS = [];
    private const GENERIC_KEYWORD_PATTERNS = [];

    /**
     * @var ProductAttributeRepositoryInterface
     */
    protected $attributeRepository;

    /**
     * @var TableFactory
     */
    protected $tableFactory;

    /**
     * @var AttributeOptionLabelInterfaceFactory
     */
    protected $optionLabelFactory;

    /**
     * @var AttributeOptionInterfaceFactory
     */
    protected $optionFactory;

    /**
     * @var AttributeOptionManagementInterface
     */
    protected $attributeOptionManagement;

    /**
     * @var HelperData
     */
    protected $helper;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [];

    /**
     * @var array<int|string, array<string, string|int>>
     */
    protected $attributeValues = [];

    /**
     * @var array<int|string, array<string, string>>
     */
    protected $attributeLabels = [];

    /**
     * @var array<string, string>
     */
    private $optionSourceAttributes = [];

    /**
     * @var array<string, bool>
     */
    private $blockedOptionLabels = [];

    /**
     * @param ProductAttributeRepositoryInterface $attributeRepository
     * @param TableFactory $tableFactory
     * @param AttributeOptionLabelInterfaceFactory $optionLabelFactory
     * @param AttributeOptionInterfaceFactory $optionFactory
     * @param AttributeOptionManagementInterface $attributeOptionManagement
     * @param HelperData $helper
     */
    public function __construct(
        ProductAttributeRepositoryInterface $attributeRepository,
        TableFactory $tableFactory,
        AttributeOptionLabelInterfaceFactory $optionLabelFactory,
        AttributeOptionInterfaceFactory $optionFactory,
        AttributeOptionManagementInterface $attributeOptionManagement,
        HelperData $helper
    ) {
        $this->attributeRepository = $attributeRepository;
        $this->tableFactory = $tableFactory;
        $this->optionLabelFactory = $optionLabelFactory;
        $this->optionFactory = $optionFactory;
        $this->attributeOptionManagement = $attributeOptionManagement;
        $this->helper = $helper;
    }

    /**
     * Apply generated metadata to the product and return a summary of fields changed.
     *
     * @param ProductInterface $product
     * @param array<string, mixed> $metadata
     * @param bool $force
     * @param bool $dryRun
     * @return array<string, mixed>
     */
    public function apply(ProductInterface $product, array $metadata, bool $force = false, bool $dryRun = false): array
    {
        $changes = [];
        $configs = $this->helper->getProductImageAnalysisAttributeConfig();
        $this->prepareConfiguredOptionSources($configs);
        $this->blockedOptionLabels = $this->helper->getProductImageAnalysisBlockedOptionLabels();
        try {
            foreach ($configs as $attributeCode => $config) {
                if (!array_key_exists($attributeCode, $metadata)) {
                    continue;
                }
                $this->applyAttribute($product, $attributeCode, $metadata[$attributeCode], $config, $force, $dryRun, $changes);
            }

            $this->dedupePromotedMultiselectValues($product, $configs, $dryRun, $changes);
        } finally {
            $this->resetConfiguredOptionSources();
        }

        return $changes;
    }

    /**
     * Build admin product form values for generated metadata without saving a product.
     *
     * Attribute option IDs are created as needed so Magento select and multiselect
     * fields can receive real option values immediately.
     *
     * @param ProductInterface $product
     * @param array<string, mixed> $metadata
     * @param bool $force
     * @return array{fields: array<string, mixed>, options: array<string, array<int, array{id: string|int, label: string}>>}
     */
    public function buildFormData(ProductInterface $product, array $metadata, bool $force = false): array
    {
        $fields = [];
        $options = [];
        $configs = $this->helper->getProductImageAnalysisAttributeConfig();
        $this->prepareConfiguredOptionSources($configs);
        $this->blockedOptionLabels = $this->helper->getProductImageAnalysisBlockedOptionLabels();
        try {
            foreach ($configs as $attributeCode => $config) {
                if (!array_key_exists($attributeCode, $metadata)) {
                    continue;
                }

                if (!$this->canUpdateConfiguredAttribute($product, $attributeCode, $force, $config['policy'])) {
                    continue;
                }

                $attribute = $this->getAttribute($attributeCode);
                if (!$attribute) {
                    continue;
                }
                $input = (string) $attribute->getFrontendInput();

                if ($input === 'multiselect' || $input === 'select') {
                    $attributeOptions = $this->createAttributeOptions($attributeCode, $metadata[$attributeCode], (bool) $config['allow_new_options']);
                    if (empty($attributeOptions)) {
                        continue;
                    }
                    $options[$attributeCode] = $attributeOptions;
                    $ids = array_column($attributeOptions, 'id');
                    $fieldValue = $this->getOptionFieldValue($product, $attributeCode, $input, $ids, $config['policy'], $force);
                    $fields[$attributeCode] = $input === 'multiselect'
                        ? $this->splitOptionValue($fieldValue)
                        : $fieldValue;
                    continue;
                }

                $fields[$attributeCode] = $this->normalizeScalarValue($metadata[$attributeCode]);
            }

            $this->dedupePromotedFormValues($product, $configs, $fields);
        } finally {
            $this->resetConfiguredOptionSources();
        }

        return [
            'fields' => $fields,
            'options' => $options,
        ];
    }

    /**
     * Determine whether all target metadata fields are already populated.
     *
     * @param ProductInterface $product
     * @return bool
     */
    public function hasGeneratedMetadata(ProductInterface $product): bool
    {
        foreach ($this->helper->getProductImageAnalysisAttributeConfig() as $attributeCode => $config) {
            if (!$this->getAttribute($attributeCode)) {
                continue;
            }
            if ($this->canUpdateConfiguredAttribute($product, $attributeCode, false, $config['policy'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply a generated value to one product attribute.
     *
     * @param ProductInterface $product
     * @param string $attributeCode
     * @param mixed $value
     * @param array{attribute: string, instruction: string, policy: string, allow_new_options: bool} $config
     * @param bool $force
     * @param bool $dryRun
     * @param array<string, mixed> $changes
     * @return void
     */
    private function applyAttribute(
        ProductInterface $product,
        string $attributeCode,
        $value,
        array $config,
        bool $force,
        bool $dryRun,
        array &$changes
    ): void {
        if (!$this->canUpdateConfiguredAttribute($product, $attributeCode, $force, $config['policy'])) {
            return;
        }

        $attribute = $this->getAttribute($attributeCode);
        if (!$attribute) {
            return;
        }
        $input = (string) $attribute->getFrontendInput();

        if ($input === 'multiselect' || $input === 'select') {
            if ($dryRun) {
                $labels = $this->getDryRunOptionChangeLabels($product, $attributeCode, $input, $value, $config, $force);
                if (!empty($labels)) {
                    $changes[$attributeCode] = $labels;
                }
                return;
            }

            $options = $this->createAttributeOptions($attributeCode, $value, (bool) $config['allow_new_options']);
            $optionIds = array_column($options, 'id');
            if (empty($optionIds)) {
                return;
            }
            $fieldValue = $this->getOptionFieldValue($product, $attributeCode, $input, $optionIds, $config['policy'], $force);
            if ($this->sameOptionValue($product->getData($attributeCode), $fieldValue)) {
                return;
            }

            $changes[$attributeCode] = $this->getChangedOptionLabels($product, $attributeCode, $fieldValue);
            $product->setData(
                $attributeCode,
                $fieldValue
            );
            return;
        }

        $normalized = $this->normalizeScalarValue($value);
        if ($normalized === '') {
            return;
        }

        $changes[$attributeCode] = $normalized;
        if (!$dryRun) {
            $product->setData($attributeCode, $normalized);
        }
    }

    /**
     * Create or resolve option records for generated option labels.
     *
     * @param string $attributeCode
     * @param mixed $values
     * @param bool $allowCreate
     * @return array<int, array{id: string|int, label: string}>
     */
    private function createAttributeOptions(string $attributeCode, $values, bool $allowCreate): array
    {
        $options = [];
        foreach ($this->normalizeListValue($values, $attributeCode) as $label) {
            $optionId = $this->createOrGetOptionId($attributeCode, $label, $allowCreate);
            if ($optionId) {
                $options[(string) $optionId] = ['id' => $optionId, 'label' => $label];
            }
        }

        return array_values($options);
    }

    /**
     * Create or resolve an option ID.
     *
     * @param string $attributeCode
     * @param string $label
     * @param bool $allowCreate
     * @return string|int|false
     */
    private function createOrGetOptionId(string $attributeCode, string $label, bool $allowCreate)
    {
        $optionId = $this->getOptionId($attributeCode, $label);
        if ($optionId) {
            return $optionId;
        }

        if (!$allowCreate) {
            return false;
        }

        /** @var OptionLabel $optionLabel */
        $optionLabel = $this->optionLabelFactory->create();
        $optionLabel->setStoreId(0);
        $optionLabel->setLabel($label);

        $option = $this->optionFactory->create();
        $option->setLabel($label);
        $option->setStoreLabels([$optionLabel]);
        $option->setSortOrder(0);
        $option->setIsDefault(false);

        try {
            $optionAttribute = $this->getAttribute($this->getOptionAttributeCode($attributeCode));
            if (!$optionAttribute) {
                return false;
            }
            $optionId = $this->attributeOptionManagement->add(
                Product::ENTITY,
                $optionAttribute->getAttributeId(),
                $option
            );
        } catch (InputException $e) {
            $optionId = $this->getOptionId($attributeCode, $label, true);
        }

        if ($optionId) {
            $this->getOptionId($attributeCode, $label, true);
            return $optionId;
        }

        return $this->getOptionId($attributeCode, $label, true);
    }

    /**
     * Resolve an existing option ID by label.
     *
     * @param string $attributeCode
     * @param string $label
     * @param bool $force
     * @return string|int|false
     */
    private function getOptionId(string $attributeCode, string $label, bool $force = false)
    {
        return $this->getOptionIdFromAttributeCode($this->getOptionAttributeCode($attributeCode), $label, $force);
    }

    /**
     * Resolve an existing option ID by label for a specific option-owning attribute.
     *
     * @param string $optionAttributeCode
     * @param string $label
     * @param bool $force
     * @return string|int|false
     */
    private function getOptionIdFromAttributeCode(string $optionAttributeCode, string $label, bool $force = false)
    {
        $attribute = $this->getAttribute($optionAttributeCode);
        if (!$attribute) {
            return false;
        }
        $attributeId = $attribute->getAttributeId();

        if ($force || !isset($this->attributeValues[$attributeId])) {
            $this->attributeValues[$attributeId] = [];
            $this->attributeLabels[$attributeId] = [];
            $sourceModel = $this->tableFactory->create();
            $sourceModel->setAttribute($attribute);

            foreach ($sourceModel->getAllOptions(true, false) as $option) {
                $optionLabel = $this->normalizeOptionLabel((string) $option['label']);
                $value = (string) $option['value'];
                if ($optionLabel === '' || $value === '') {
                    continue;
                }
                $optionLabelKey = $this->getOptionLabelKey($optionLabel);
                if (!isset($this->attributeValues[$attributeId][$optionLabelKey])
                    || (int) $value < (int) $this->attributeValues[$attributeId][$optionLabelKey]
                ) {
                    $this->attributeValues[$attributeId][$optionLabelKey] = $option['value'];
                }
                $this->attributeLabels[$attributeId][$value] = $optionLabel;
            }
        }

        return $this->attributeValues[$attributeId][$this->getOptionLabelKey($label)] ?? false;
    }

    /**
     * Normalize an option label for display and duplicate-safe matching.
     *
     * @param string $label
     * @return string
     */
    private function normalizeOptionLabel(string $label): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $label));
    }

    /**
     * Build a duplicate-safe option lookup key.
     *
     * @param string $label
     * @return string
     */
    private function getOptionLabelKey(string $label): string
    {
        return strtolower($this->normalizeOptionLabel($label));
    }

    /**
     * Resolve the configured attribute that owns option IDs for a target field.
     *
     * @param string $attributeCode
     * @return string
     */
    private function getOptionAttributeCode(string $attributeCode): string
    {
        return $this->optionSourceAttributes[$attributeCode] ?? $attributeCode;
    }

    /**
     * Get product attribute metadata.
     *
     * @param string $attributeCode
     * @return mixed
     */
    private function getAttribute(string $attributeCode)
    {
        if (!array_key_exists($attributeCode, $this->attributes)) {
            try {
                $this->attributes[$attributeCode] = $this->attributeRepository->get($attributeCode);
            } catch (\Exception $e) {
                $this->attributes[$attributeCode] = null;
            }
        }

        return $this->attributes[$attributeCode];
    }

    /**
     * Build the final option field value for select/multiselect attributes.
     *
     * @param ProductInterface $product
     * @param string $attributeCode
     * @param string $input
     * @param array<int, string|int> $generatedIds
     * @param string $policy
     * @param bool $force
     * @return string|string[]
     */
    private function getOptionFieldValue(
        ProductInterface $product,
        string $attributeCode,
        string $input,
        array $generatedIds,
        string $policy,
        bool $force
    ) {
        $generatedIds = $this->normalizeOptionIds($attributeCode, $generatedIds);
        if ($input === 'select') {
            return (string) reset($generatedIds);
        }

        if (!$force && in_array($policy, [HelperData::IMAGE_ANALYSIS_POLICY_MERGE, HelperData::IMAGE_ANALYSIS_POLICY_MERGE_PROMOTE], true)) {
            $generatedIds = $this->normalizeOptionIds($attributeCode, array_merge(
                $this->getSelectedOptionIds($product, $attributeCode),
                $generatedIds
            ));
        }

        return implode(',', $generatedIds);
    }

    /**
     * Normalize option IDs against a configured shared option source.
     *
     * @param string $attributeCode
     * @param array<int, string|int> $optionIds
     * @return string[]
     */
    private function normalizeOptionIds(string $attributeCode, array $optionIds): array
    {
        $optionIds = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $optionIds)), 'strlen')));
        if ($this->getOptionAttributeCode($attributeCode) === $attributeCode) {
            return $optionIds;
        }

        return $this->translateKeywordOptionIds($attributeCode, $optionIds);
    }

    /**
     * Get labels that would change during a dry run.
     *
     * @param ProductInterface $product
     * @param string $attributeCode
     * @param string $input
     * @param mixed $value
     * @param array{attribute: string, instruction: string, policy: string, allow_new_options: bool} $config
     * @param bool $force
     * @return string[]
     */
    private function getDryRunOptionChangeLabels(
        ProductInterface $product,
        string $attributeCode,
        string $input,
        $value,
        array $config,
        bool $force
    ): array {
        $labels = $this->normalizeListValue($value, $attributeCode);
        if (empty($labels)) {
            return [];
        }

        if (!$config['allow_new_options']) {
            $labels = $this->filterExistingOptionLabels($attributeCode, $labels);
        }
        if (empty($labels)) {
            return [];
        }

        if ($input === 'select' || $force || !in_array($config['policy'], [HelperData::IMAGE_ANALYSIS_POLICY_MERGE, HelperData::IMAGE_ANALYSIS_POLICY_MERGE_PROMOTE], true)) {
            return $labels;
        }

        $existing = array_map('strtolower', $this->getSelectedOptionLabels($product, $attributeCode));
        return array_values(array_filter($labels, function ($label) use ($existing) {
            return !in_array(strtolower($label), $existing, true);
        }));
    }

    /**
     * Keep only generated labels that already exist as options.
     *
     * @param string $attributeCode
     * @param string[] $labels
     * @return string[]
     */
    private function filterExistingOptionLabels(string $attributeCode, array $labels): array
    {
        $existing = [];
        foreach ($labels as $label) {
            if ($this->getOptionId($attributeCode, $label)) {
                $existing[] = $label;
            }
        }

        return $existing;
    }

    /**
     * Get selected option IDs from a product attribute value.
     *
     * @param ProductInterface $product
     * @param string $attributeCode
     * @return string[]
     */
    private function getSelectedOptionIds(ProductInterface $product, string $attributeCode): array
    {
        $value = $product->getData($attributeCode);
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = explode(',', (string) $value);
        }

        $ids = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $ids[] = $part;
            }
        }

        $ids = array_values(array_unique($ids));
        if ($this->getOptionAttributeCode($attributeCode) === $attributeCode) {
            return $ids;
        }

        return $this->translateKeywordOptionIds($attributeCode, $ids);
    }

    /**
     * Translate target option IDs to IDs from its configured option source.
     * Unmapped legacy IDs are retained rather than dropped to avoid data loss.
     *
     * @param string $attributeCode
     * @param string[] $ids
     * @return string[]
     */
    private function translateKeywordOptionIds(string $attributeCode, array $ids): array
    {
        $sourceAttributeCode = $this->getOptionAttributeCode($attributeCode);
        $translated = [];
        foreach ($ids as $id) {
            $sharedLabels = $this->getOptionLabelsByIds($sourceAttributeCode, [$id], false);
            $sharedLabel = $sharedLabels[0] ?? '';
            if ($sharedLabel !== '') {
                $sharedOptionId = $this->getOptionIdFromAttributeCode($sourceAttributeCode, $sharedLabel);
                $translated[] = $sharedOptionId ? (string) $sharedOptionId : (string) $id;
                continue;
            }

            $legacyLabels = $this->getOptionLabelsByIds($attributeCode, [$id], false);
            $legacyLabel = $legacyLabels[0] ?? '';
            if ($legacyLabel !== '') {
                $sharedOptionId = $this->getOptionIdFromAttributeCode($sourceAttributeCode, $legacyLabel);
                if ($sharedOptionId) {
                    $translated[] = (string) $sharedOptionId;
                    continue;
                }
            }

            $translated[] = (string) $id;
        }

        return array_values(array_unique($translated));
    }

    /**
     * Get selected option labels for a product attribute.
     *
     * @param ProductInterface $product
     * @param string $attributeCode
     * @return string[]
     */
    private function getSelectedOptionLabels(ProductInterface $product, string $attributeCode): array
    {
        return $this->getOptionLabelsByIds($attributeCode, $this->getSelectedOptionIds($product, $attributeCode));
    }

    /**
     * Get labels for option IDs.
     *
     * @param string $attributeCode
     * @param array<int, string|int> $ids
     * @param bool $useSharedOptionAttribute
     * @return string[]
     */
    private function getOptionLabelsByIds(string $attributeCode, array $ids, bool $useSharedOptionAttribute = true): array
    {
        $optionAttributeCode = $useSharedOptionAttribute ? $this->getOptionAttributeCode($attributeCode) : $attributeCode;
        $attribute = $this->getAttribute($optionAttributeCode);
        if (!$attribute) {
            return [];
        }
        $attributeId = $attribute->getAttributeId();
        $this->getOptionIdFromAttributeCode($optionAttributeCode, '__mageai_cache_warm__');

        $labels = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if (isset($this->attributeLabels[$attributeId][$id])) {
                $labels[] = $this->attributeLabels[$attributeId][$id];
            }
        }

        return $labels;
    }

    /**
     * Determine which option labels changed compared to current product data.
     *
     * @param ProductInterface $product
     * @param string $attributeCode
     * @param string|string[] $fieldValue
     * @return string[]
     */
    private function getChangedOptionLabels(ProductInterface $product, string $attributeCode, $fieldValue): array
    {
        $newIds = is_array($fieldValue) ? $fieldValue : explode(',', (string) $fieldValue);
        $oldIds = $this->getSelectedOptionIds($product, $attributeCode);
        $changedIds = array_values(array_diff(array_map('strval', $newIds), array_map('strval', $oldIds)));

        return $this->getOptionLabelsByIds($attributeCode, $changedIds ?: $newIds);
    }

    /**
     * Compare select/multiselect field values.
     *
     * @param mixed $current
     * @param mixed $next
     * @return bool
     */
    private function sameOptionValue($current, $next): bool
    {
        $currentIds = is_array($current) ? $current : explode(',', (string) $current);
        $nextIds = is_array($next) ? $next : explode(',', (string) $next);
        $currentIds = array_values(array_filter(array_map('trim', array_map('strval', $currentIds)), 'strlen'));
        $nextIds = array_values(array_filter(array_map('trim', array_map('strval', $nextIds)), 'strlen'));
        sort($currentIds);
        sort($nextIds);

        return $currentIds === $nextIds;
    }

    /**
     * Split a select/multiselect value into option ID strings.
     *
     * @param mixed $value
     * @return string[]
     */
    private function splitOptionValue($value): array
    {
        $ids = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_filter(array_map('trim', array_map('strval', $ids)), 'strlen'));
    }

    /**
     * Remove promoted labels from later configured multiselect attributes.
     *
     * @param ProductInterface $product
     * @param array<string, array{attribute: string, instruction: string, policy: string, allow_new_options: bool}> $configs
     * @param bool $dryRun
     * @param array<string, mixed> $changes
     * @return void
     */
    private function dedupePromotedMultiselectValues(ProductInterface $product, array $configs, bool $dryRun, array &$changes): void
    {
        $promoted = [];
        foreach ($configs as $attributeCode => $config) {
            $attribute = $this->getAttribute($attributeCode);
            if (!$attribute || (string) $attribute->getFrontendInput() !== 'multiselect') {
                continue;
            }

            $labels = $this->getSelectedOptionLabels($product, $attributeCode);
            if ($dryRun && isset($changes[$attributeCode]) && is_array($changes[$attributeCode])) {
                $labels = array_merge($labels, $changes[$attributeCode]);
            }

            if (!empty($promoted)) {
                $ids = $this->getSelectedOptionIds($product, $attributeCode);
                $remainingIds = [];
                $removed = [];
                foreach ($ids as $id) {
                    $label = $this->getOptionLabelsByIds($attributeCode, [$id])[0] ?? '';
                    if ($label !== '' && isset($promoted[strtolower($label)])) {
                        $removed[] = $label;
                        continue;
                    }
                    $remainingIds[] = $id;
                }

                if (!empty($removed)) {
                    $changes[$attributeCode] = array_values(array_unique(array_merge(
                        isset($changes[$attributeCode]) && is_array($changes[$attributeCode]) ? $changes[$attributeCode] : [],
                        $removed
                    )));
                    if (!$dryRun) {
                        $product->setData($attributeCode, implode(',', $remainingIds));
                    }
                }
            }

            if ($config['policy'] === HelperData::IMAGE_ANALYSIS_POLICY_MERGE_PROMOTE) {
                foreach ($labels as $label) {
                    $promoted[strtolower($label)] = true;
                }
            }
        }
    }

    /**
     * Remove promoted labels from later form values for admin AJAX responses.
     *
     * @param ProductInterface $product
     * @param array<string, array{attribute: string, instruction: string, policy: string, allow_new_options: bool}> $configs
     * @param array<string, mixed> $fields
     * @return void
     */
    private function dedupePromotedFormValues(ProductInterface $product, array $configs, array &$fields): void
    {
        $promoted = [];
        foreach ($configs as $attributeCode => $config) {
            $attribute = $this->getAttribute($attributeCode);
            if (!$attribute || (string) $attribute->getFrontendInput() !== 'multiselect') {
                continue;
            }

            $ids = isset($fields[$attributeCode]) && is_array($fields[$attributeCode])
                ? $fields[$attributeCode]
                : $this->getSelectedOptionIds($product, $attributeCode);

            if (!empty($promoted)) {
                $remainingIds = [];
                foreach ($ids as $id) {
                    $label = $this->getOptionLabelsByIds($attributeCode, [$id])[0] ?? '';
                    if ($label !== '' && isset($promoted[strtolower($label)])) {
                        continue;
                    }
                    $remainingIds[] = (string) $id;
                }
                if ($remainingIds !== array_map('strval', $ids)) {
                    $fields[$attributeCode] = $remainingIds;
                }
            }

            if ($config['policy'] === HelperData::IMAGE_ANALYSIS_POLICY_MERGE_PROMOTE) {
                foreach ($this->getOptionLabelsByIds($attributeCode, $ids) as $label) {
                    $promoted[strtolower($label)] = true;
                }
            }
        }
    }

    /**
     * Normalize a generated list value.
     *
     * @param mixed $values
     * @param string $attributeCode
     * @return string[]
     */
    private function normalizeListValue($values, string $attributeCode = ''): array
    {
        if (is_string($values)) {
            $values = explode(',', $values);
        }
        if (!is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $normalized[strtolower($value)] = $value;
            }
        }

        return array_values(array_filter($normalized, function (string $label): bool {
            return !isset($this->blockedOptionLabels[strtolower($label)]);
        }));
    }

    /**
     * Remove generic generated keyword labels before creating or merging options.
     *
     * @param string $attributeCode
     * @param string[] $labels
     * @return string[]
     */
    private function filterGeneratedKeywordLabels(string $attributeCode, array $labels): array
    {
        if (!isset(self::KEYWORD_ATTRIBUTE_CODES[$attributeCode])) {
            return $labels;
        }

        $filtered = [];
        foreach ($labels as $label) {
            $label = $this->normalizeKeywordLabel($label);
            if ($label === '' || $this->isGenericKeywordLabel($label) || !$this->isKeywordLabelSupportedByContext($label)) {
                continue;
            }
            $filtered[strtolower($label)] = $label;
        }

        return array_values($filtered);
    }

    /**
     * Limit generated keyword labels so noisy model output cannot swamp curated data.
     *
     * @param string $attributeCode
     * @param string[] $labels
     * @return string[]
     */
    private function limitGeneratedKeywordLabels(string $attributeCode, array $labels): array
    {
        if (!isset(self::MAX_GENERATED_KEYWORD_LABELS[$attributeCode])) {
            return $labels;
        }

        return array_slice($labels, 0, self::MAX_GENERATED_KEYWORD_LABELS[$attributeCode]);
    }

    /**
     * Normalize one generated keyword label before filtering and option lookup.
     *
     * @param string $label
     * @return string
     */
    private function normalizeKeywordLabel(string $label): string
    {
        $label = trim(strip_tags($label));
        $label = preg_replace('/\s+/', ' ', $label);
        $label = trim((string) $label, " \t\n\r\0\x0B,.;:!?\"'()[]{}");

        return $label;
    }

    /**
     * Determine whether a generated keyword label is too generic to be useful.
     *
     * @param string $label
     * @return bool
     */
    private function isGenericKeywordLabel(string $label): bool
    {
        $normalized = strtolower($label);
        if (isset(self::GENERIC_KEYWORD_LABELS[$normalized])) {
            return true;
        }

        foreach (self::GENERIC_KEYWORD_PATTERNS as $pattern) {
            if (preg_match($pattern, $label) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build scalar text context used to reject unsupported generated keyword labels.
     *
     * @param ProductInterface $product
     * @param array<string, mixed> $metadata
     * @return string
     */
    private function buildKeywordContext(ProductInterface $product, array $metadata): string
    {
        $parts = [];
        foreach (['name', 'description', 'short_description', 'meta_title', 'meta_description', 'meta_keyword'] as $attributeCode) {
            if (array_key_exists($attributeCode, $metadata)) {
                $parts[] = $this->flattenKeywordContextValue($metadata[$attributeCode]);
            }
            $parts[] = $this->flattenKeywordContextValue($product->getData($attributeCode));
        }

        $context = strtolower(trim(strip_tags(implode(' ', $parts))));
        $context = preg_replace('/\s+/', ' ', $context);

        return (string) $context;
    }

    /**
     * Flatten a scalar or nested generated value for keyword context matching.
     *
     * @param mixed $value
     * @return string
     */
    private function flattenKeywordContextValue($value): string
    {
        if (is_array($value)) {
            return implode(' ', array_map(function ($item) {
                return $this->flattenKeywordContextValue($item);
            }, $value));
        }

        return trim(strip_tags((string) $value));
    }

    /**
     * Require generated keyword labels to be supported by generated scalar metadata.
     *
     * @param string $label
     * @return bool
     */
    private function isKeywordLabelSupportedByContext(string $label): bool
    {
        if ($this->keywordContext === '') {
            return true;
        }

        $words = $this->getKeywordLabelWords($label);
        if (empty($words)) {
            return false;
        }

        foreach ($words as $word) {
            if ($this->keywordContextContainsWord($word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract meaningful words from one keyword label.
     *
     * @param string $label
     * @return string[]
     */
    private function getKeywordLabelWords(string $label): array
    {
        $words = preg_split('/[^a-z0-9]+/i', strtolower($label)) ?: [];
        $stopWords = [
            'a' => true,
            'an' => true,
            'and' => true,
            'as' => true,
            'at' => true,
            'by' => true,
            'for' => true,
            'from' => true,
            'in' => true,
            'into' => true,
            'of' => true,
            'on' => true,
            'or' => true,
            'the' => true,
            'to' => true,
            'under' => true,
            'with' => true,
        ];

        $meaningful = [];
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) < 3 || isset($stopWords[$word])) {
                continue;
            }
            $meaningful[$word] = $word;
        }

        return array_values($meaningful);
    }

    /**
     * Check keyword context for singular or plural forms of a word.
     *
     * @param string $word
     * @return bool
     */
    private function keywordContextContainsWord(string $word): bool
    {
        $forms = [$word];
        if (strlen($word) > 3 && substr($word, -1) === 's') {
            $forms[] = substr($word, 0, -1);
        } elseif (strlen($word) > 3) {
            $forms[] = $word . 's';
        }

        foreach (array_unique($forms) as $form) {
            if (preg_match('/\b' . preg_quote($form, '/') . '\b/i', $this->keywordContext) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a generated scalar value.
     *
     * @param mixed $value
     * @return string
     */
    private function normalizeScalarValue($value): string
    {
        if (is_array($value)) {
            $value = implode(', ', $this->normalizeListValue($value));
        }

        return trim((string) $value);
    }

    /**
     * Determine whether a product attribute may be updated.
     *
     * @param ProductInterface $product
     * @param string $attributeCode
     * @param bool $force
     * @param string $policy
     * @return bool
     */
    public function canUpdateConfiguredAttribute(ProductInterface $product, string $attributeCode, bool $force, string $policy): bool
    {
        if (!$this->getAttribute($attributeCode)) {
            return false;
        }
        if ($force) {
            return true;
        }

        if ($attributeCode === 'name' && $this->isPlaceholderTitle($product)) {
            return true;
        }

        if (in_array($policy, [HelperData::IMAGE_ANALYSIS_POLICY_REPLACE, HelperData::IMAGE_ANALYSIS_POLICY_MERGE, HelperData::IMAGE_ANALYSIS_POLICY_MERGE_PROMOTE], true)) {
            return true;
        }

        if ($policy === HelperData::IMAGE_ANALYSIS_POLICY_PLACEHOLDER && $attributeCode === 'name') {
            return $this->isPlaceholderTitle($product);
        }

        return !$this->hasValue($product->getData($attributeCode));
    }

    /**
     * Build generic option-source mappings from explicit configuration.
     *
     * Legacy rows that use an earlier merge-and-promote row retain their shared
     * option source until an administrator saves an explicit Option Source value.
     *
     * @param array<string, array{attribute: string, instruction: string, policy: string, allow_new_options: bool, option_source_attribute: string}> $configs
     * @return void
     */
    private function prepareConfiguredOptionSources(array $configs): void
    {
        $promotionSource = '';
        foreach ($configs as $attributeCode => $config) {
            $source = trim((string) ($config['option_source_attribute'] ?? ''));
            if ($source === '' && $promotionSource !== '' && $config['policy'] === HelperData::IMAGE_ANALYSIS_POLICY_MERGE) {
                $source = $promotionSource;
            }
            $this->optionSourceAttributes[$attributeCode] = $source !== '' ? $source : $attributeCode;
            if ($config['policy'] === HelperData::IMAGE_ANALYSIS_POLICY_MERGE_PROMOTE) {
                $promotionSource = $this->optionSourceAttributes[$attributeCode];
            }
        }
    }

    /**
     * Clear request-specific option configuration after applying metadata.
     *
     * @return void
     */
    private function resetConfiguredOptionSources(): void
    {
        $this->optionSourceAttributes = [];
        $this->blockedOptionLabels = [];
    }

    /**
     * Check whether a value contains useful data.
     *
     * @param mixed $value
     * @return bool
     */
    private function hasValue($value): bool
    {
        if (is_array($value)) {
            return !empty(array_filter($value));
        }

        return trim((string) $value) !== '';
    }

    /**
     * Product import often uses the SKU as a temporary title; allow replacing it.
     *
     * @param ProductInterface $product
     * @return bool
     */
    private function isPlaceholderTitle(ProductInterface $product): bool
    {
        $name = trim((string) $product->getName());
        $sku = trim((string) $product->getSku());

        return $name === '' || ($sku !== '' && strcasecmp($name, $sku) === 0);
    }
}
