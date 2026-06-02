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
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Mageprince\MageAI\Helper\Data as HelperData;
use Mageprince\MageAI\Model\Query\Completions;
use Mageprince\MageAI\Model\Query\QueryException;

class Generate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Mageprince_MageAI::generate';

    /**
     * @var JsonFactory
     */
    protected $resultJson;

    /**
     * @var Completions
     */
    protected $queryCompletion;

    /**
     * @var HelperData
     */
    protected $helper;

    /**
     * @param Action\Context $context
     * @param JsonFactory $resultJson
     * @param Completions $queryCompletion
     * @param HelperData $helper
     */
    public function __construct(
        Action\Context $context,
        JsonFactory $resultJson,
        Completions $queryCompletion,
        HelperData $helper
    ) {
        $this->resultJson = $resultJson;
        $this->queryCompletion = $queryCompletion;
        $this->helper = $helper;
        parent::__construct($context);
    }

    /**
     * Generate Content
     *
     * @return \Magento\Framework\App\ResponseInterface
     */
    public function execute()
    {
        $response = ['error' => true, 'data' => __('An unknown error occurred.')];

        if ($this->helper->isEnabled()) {
            try {
                $customPrompt = $this->getRequest()->getParam('custom_prompt');

                if ($customPrompt === 'false') {
                    $attributeData = $this->getRequest()->getParam('attribute_data', []);

                    if (!is_array($attributeData) || empty($attributeData)) {
                        $response = [
                            'error' => true,
                            'data'  => __('No attribute data was received. Please ensure the configured attributes have values in the product form.')
                        ];
                    } else {
                        $type = $this->getRequest()->getParam('type');
                        $data = $this->queryCompletion->generateProductDescriptionFromData(
                            $attributeData,
                            $type
                        );
                        $response = ['error' => false, 'data' => $data];
                    }
                } else {
                    $data = $this->queryCompletion->generateCustomContent($customPrompt);
                    $response = ['error' => false, 'data' => $data];
                }
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
