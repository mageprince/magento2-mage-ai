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

namespace Mageprince\MageAI\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Mageprince\MageAI\Helper\Data as HelperData;
use Mageprince\MageAI\Model\AttributeData\Formatter as AttributeFormatter;
use Mageprince\MageAI\Model\Query\ImageModification;
use Mageprince\MageAI\Model\Query\QueryException;

class ModifyImage extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Mageprince_MageAI::generate';

    /**
     * @var JsonFactory
     */
    protected $resultJson;

    /**
     * @var ImageModification
     */
    protected $imageModification;

    /**
     * @var HelperData
     */
    protected $helper;

    /**
     * @var AttributeFormatter
     */
    protected $attributeFormatter;

    /**
     * @param Action\Context $context
     * @param JsonFactory $resultJson
     * @param ImageModification $imageModification
     * @param HelperData $helper
     * @param AttributeFormatter $attributeFormatter
     */
    public function __construct(
        Action\Context $context,
        JsonFactory $resultJson,
        ImageModification $imageModification,
        HelperData $helper,
        AttributeFormatter $attributeFormatter
    ) {
        $this->resultJson = $resultJson;
        $this->imageModification = $imageModification;
        $this->helper = $helper;
        $this->attributeFormatter = $attributeFormatter;
        parent::__construct($context);
    }

    /**
     * Modify an existing product image
     *
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $response = ['error' => true, 'data' => __('An unknown error occurred.')];

        if ($this->helper->isEnabled()) {
            try {
                $customPrompt = trim((string) $this->getRequest()->getParam('custom_prompt', ''));
                $imageFile = trim((string) $this->getRequest()->getParam('image_file', ''));
                $productName = (string) $this->getRequest()->getParam('product_name', '');
                $attributeData = $this->getRequest()->getParam('attribute_data', []);
                if (!is_array($attributeData)) {
                    $attributeData = [];
                }

                if ($imageFile === '') {
                    throw new QueryException(__('No source image was selected to modify.'));
                }

                // Use the custom prompt when provided, otherwise the configured default modify prompt.
                // Both support the {{ product.name }} and {{ product.attributes }} variables.
                $prompt = $customPrompt !== '' ? $customPrompt : $this->helper->getImageModifyDefaultPrompt();
                $prompt = str_replace('{{ product.name }}', $productName, $prompt);
                $prompt = str_replace(
                    '{{ product.attributes }}',
                    $this->attributeFormatter->buildLabelValueText($attributeData),
                    $prompt
                );

                $imageData = $this->imageModification->modify($prompt, $imageFile);
                return $this->resultJson->create()->setData($imageData);
            } catch (QueryException $e) {
                $response = ['error' => true, 'data' => $e->getMessage()];
            } catch (\Exception $e) {
                $response = ['error' => true, 'data' => $e->getMessage()];
            }
        }

        return $this->resultJson->create()->setData($response);
    }

    /**
     * @inheritDoc
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
