<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */
// phpcs:disable Generic.Files.LineLength

namespace Mageprince\MageAI\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Mageprince\MageAI\Helper\Data as HelperData;
use Mageprince\MageAI\Model\ProductMetadata\ImageAnalyzer;
use Mageprince\MageAI\Model\ProductMetadata\MetadataApplier;
use Mageprince\MageAI\Model\Query\QueryException;

class AnalyzeImage extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Mageprince_MageAI::generate';

    /**
     * @var JsonFactory
     */
    protected $resultJson;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var HelperData
     */
    protected $helper;

    /**
     * @var ImageAnalyzer
     */
    protected $imageAnalyzer;

    /**
     * @var MetadataApplier
     */
    protected $metadataApplier;

    /**
     * @param Action\Context $context
     * @param JsonFactory $resultJson
     * @param ProductRepositoryInterface $productRepository
     * @param HelperData $helper
     * @param ImageAnalyzer $imageAnalyzer
     * @param MetadataApplier $metadataApplier
     */
    public function __construct(
        Action\Context $context,
        JsonFactory $resultJson,
        ProductRepositoryInterface $productRepository,
        HelperData $helper,
        ImageAnalyzer $imageAnalyzer,
        MetadataApplier $metadataApplier
    ) {
        $this->resultJson = $resultJson;
        $this->productRepository = $productRepository;
        $this->helper = $helper;
        $this->imageAnalyzer = $imageAnalyzer;
        $this->metadataApplier = $metadataApplier;
        parent::__construct($context);
    }

    /**
     * Analyze product image and return product form field values.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        if (!$this->helper->isEnabled()) {
            return $this->resultJson->create()->setData([
                'error' => true,
                'data' => __('MageAI is disabled.'),
            ]);
        }

        $productId = (int) $this->getRequest()->getParam('product_id');
        if ($productId <= 0) {
            return $this->resultJson->create()->setData([
                'error' => true,
                'data' => __('Please save the product before regenerating metadata from its image.'),
            ]);
        }

        try {
            $product = $this->productRepository->getById($productId, false, 0, true);
            $metadata = $this->imageAnalyzer->analyze($product);

            return $this->resultJson->create()->setData([
                'error' => false,
                'data' => $this->metadataApplier->buildFormData($product, $metadata),
            ]);
        } catch (QueryException $e) {
            return $this->resultJson->create()->setData(['error' => true, 'data' => $e->getMessage()]);
        } catch (\Exception $e) {
            return $this->resultJson->create()->setData(['error' => true, 'data' => $e->getMessage()]);
        }
    }

    /**
     * @inheritDoc
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
