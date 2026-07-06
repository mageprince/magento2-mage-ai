<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */

namespace Mageprince\MageAI\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

class ImageAnalysisAttributes implements OptionSourceInterface
{
    /**
     * Supported frontend inputs for safe AI-generated catalog updates.
     */
    private const SUPPORTED_INPUTS = ['text', 'textarea', 'select', 'multiselect'];

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(CollectionFactory $collectionFactory)
    {
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * Get safe product attribute options in key-value format.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $attributes = [];
        $attributeCollection = $this->collectionFactory->create();
        $attributeCollection->addFieldToFilter('frontend_input', ['in' => self::SUPPORTED_INPUTS]);

        foreach ($attributeCollection->getItems() as $attribute) {
            $label = trim((string) $attribute->getFrontendLabel());
            $code = (string) $attribute->getAttributeCode();
            if ($label !== '' && $code !== '') {
                $attributes[$code] = sprintf('%s (%s)', $label, $code);
            }
        }

        asort($attributes);
        return $attributes;
    }

    /**
     * Options getter.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $optionArray = [];
        foreach ($this->toArray() as $value => $label) {
            $optionArray[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $optionArray;
    }
}
