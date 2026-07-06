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
use Mageprince\MageAI\Model\ProductMetadata\Queue\MissingDataScorer;
use Mageprince\MageAI\Model\ProductMetadata\Queue\QueueManager;

class QueueImageMetadata extends Action implements HttpPostActionInterface
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
     * @var MissingDataScorer
     */
    protected $missingDataScorer;

    /**
     * @var QueueManager
     */
    protected $queueManager;

    /**
     * @param Action\Context $context
     * @param JsonFactory $resultJson
     * @param ProductRepositoryInterface $productRepository
     * @param HelperData $helper
     * @param MissingDataScorer $missingDataScorer
     * @param QueueManager $queueManager
     */
    public function __construct(
        Action\Context $context,
        JsonFactory $resultJson,
        ProductRepositoryInterface $productRepository,
        HelperData $helper,
        MissingDataScorer $missingDataScorer,
        QueueManager $queueManager
    ) {
        $this->resultJson = $resultJson;
        $this->productRepository = $productRepository;
        $this->helper = $helper;
        $this->missingDataScorer = $missingDataScorer;
        $this->queueManager = $queueManager;
        parent::__construct($context);
    }

    /**
     * Queue product image metadata generation for CLI workers.
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
                'data' => __('Please save the product before queueing metadata generation from its image.'),
            ]);
        }

        try {
            $product = $this->productRepository->getById($productId, false, 0, true);
            $score = $this->missingDataScorer->score($product);
            if ((int) $score['score'] <= 0 || empty($score['fields'])) {
                return $this->resultJson->create()->setData([
                    'error' => false,
                    'data' => [
                        'product_id' => $productId,
                        'queue_id' => null,
                        'queue_status' => null,
                        'missing_score' => 0,
                        'missing_fields' => [],
                        'created' => false,
                        'updated' => false,
                        'affected_rows' => 0,
                        'message' => __('No image metadata fields need generation.'),
                    ],
                ]);
            }
            $existingRow = $this->queueManager->getByProductId($productId);
            $affectedRows = $this->queueManager->enqueue($product, (int) $score['score'], $score['fields']);
            $queueRow = $this->queueManager->getByProductId($productId);

            return $this->resultJson->create()->setData([
                'error' => false,
                'data' => [
                    'product_id' => $productId,
                    'queue_id' => isset($queueRow['queue_id']) ? (int) $queueRow['queue_id'] : null,
                    'queue_status' => isset($queueRow['status']) ? (string) $queueRow['status'] : QueueManager::STATUS_PENDING,
                    'missing_score' => (int) $score['score'],
                    'missing_fields' => $score['fields'],
                    'created' => $existingRow === null,
                    'updated' => $existingRow !== null,
                    'affected_rows' => $affectedRows,
                ],
            ]);
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
