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

namespace Mageprince\MageAI\Model\AttributeData;

use Magento\Eav\Model\Config as EavConfig;

/**
 * Builds human-readable "Label: Value" text from raw product attribute form data.
 * Shared by the description and image generation prompt builders.
 */
class Formatter
{
    /**
     * @var EavConfig
     */
    private $eavConfig;

    /**
     * @param EavConfig $eavConfig
     */
    public function __construct(EavConfig $eavConfig)
    {
        $this->eavConfig = $eavConfig;
    }

    /**
     * Build a comma-separated "Label: Value" string from a code => value map
     *
     * Entries with an empty/null value are skipped.
     *
     * @param array $data ['attributeCode' => 'displayValue', ...]
     * @return string
     */
    public function buildLabelValueText(array $data): string
    {
        $parts = [];
        foreach ($data as $code => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = $this->resolveAttributeLabel((string) $code) . ': ' . $value;
        }
        return implode(', ', $parts);
    }

    /**
     * Resolve a catalog_product attribute code to its frontend label
     *
     * Falls back to a humanised version of the code if the attribute is not found.
     *
     * @param string $code
     * @return string
     */
    public function resolveAttributeLabel(string $code): string
    {
        try {
            $attribute = $this->eavConfig->getAttribute('catalog_product', $code);
            if ($attribute && $attribute->getAttributeId()) {
                return $attribute->getDefaultFrontendLabel() ?: $this->humanizeCode($code);
            }
        } catch (\Exception $e) {
            return $this->humanizeCode($code);
        }
        return $this->humanizeCode($code);
    }

    /**
     * Convert an attribute code to a human-readable label (e.g. short_description → Short Description)
     *
     * @param string $code
     * @return string
     */
    private function humanizeCode(string $code): string
    {
        return ucwords(str_replace('_', ' ', $code));
    }
}
