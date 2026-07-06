<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */
// phpcs:disable Generic.Files.LineLength

namespace Mageprince\MageAI\Model\ProductMetadata\Queue;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\App\ResourceConnection;

class QueueManager
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    private const TABLE = 'mageprince_mageai_image_metadata_queue';

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @param ResourceConnection $resource
     * @param Json $json
     * @param DateTime $dateTime
     */
    public function __construct(
        ResourceConnection $resource,
        Json $json,
        DateTime $dateTime
    ) {
        $this->resource = $resource;
        $this->json = $json;
        $this->dateTime = $dateTime;
    }

    /**
     * Upsert one product into the image metadata queue.
     *
     * @param ProductInterface $product
     * @param int $missingScore
     * @param string[] $missingFields
     * @return int Affected row count
     */
    public function enqueue(ProductInterface $product, int $missingScore, array $missingFields): int
    {
        return $this->enqueueRows([[
            'product_id' => (int) $product->getId(),
            'sku' => (string) $product->getSku(),
            'product_type' => (string) $product->getTypeId(),
            'missing_score' => $missingScore,
            'missing_fields' => $missingFields,
        ]]);
    }

    /**
     * Upsert queue rows without calling AI services or saving products.
     *
     * @param array<int, array{product_id: int, sku: string, product_type: string, missing_score: int, missing_fields: string[]}> $rows
     * @return int Affected row count
     */
    public function enqueueRows(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $processingProductIds = $this->getProcessingProductIds(array_map(function (array $row): int {
            return (int) $row['product_id'];
        }, $rows));
        $now = $this->dateTime->gmtDate();
        $data = [];
        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            if (isset($processingProductIds[$productId])) {
                continue;
            }

            $data[] = [
                'product_id' => $productId,
                'sku' => (string) $row['sku'],
                'product_type' => (string) $row['product_type'],
                'missing_score' => max(0, (int) $row['missing_score']),
                'missing_fields' => $this->json->serialize(array_values($row['missing_fields'])),
                'status' => self::STATUS_PENDING,
                'locked_at' => null,
                'locked_by' => null,
                'last_error' => null,
                'updated_at' => $now,
                'processed_at' => null,
            ];
        }

        if (empty($data)) {
            return 0;
        }

        return $this->getConnection()->insertOnDuplicate(
            $this->getTableName(),
            $data,
            ['sku', 'product_type', 'missing_score', 'missing_fields', 'status', 'locked_at', 'locked_by', 'last_error', 'updated_at', 'processed_at']
        );
    }

    /**
     * Atomically claim pending work for one worker.
     *
     * @param int $limit
     * @param string $workerId
     * @param int $maxAttempts
     * @return array<int, array<string, mixed>>
     */
    public function claim(int $limit, string $workerId, int $maxAttempts = 0): array
    {
        if ($limit <= 0) {
            return [];
        }

        $connection = $this->getConnection();
        $connection->beginTransaction();
        try {
            $select = $connection->select()
                ->from($this->getTableName(), ['queue_id'])
                ->where('status = ?', self::STATUS_PENDING)
                ->order(['missing_score DESC', 'product_id ASC', 'queue_id ASC'])
                ->limit($limit)
                ->forUpdate(true);
            if ($maxAttempts > 0) {
                $select->where('attempts < ?', $maxAttempts);
            }
            $ids = array_map('intval', $connection->fetchCol($select));

            if (!empty($ids)) {
                $connection->update(
                    $this->getTableName(),
                    [
                        'status' => self::STATUS_PROCESSING,
                        'locked_at' => $this->dateTime->gmtDate(),
                        'locked_by' => $workerId,
                        'attempts' => new \Zend_Db_Expr('attempts + 1'),
                        'updated_at' => $this->dateTime->gmtDate(),
                    ],
                    ['queue_id IN (?)' => $ids]
                );
            }

            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }

        return empty($ids) ? [] : $this->getRowsByIds($ids);
    }

    /**
     * Preview pending work without mutating queue state.
     *
     * @param int $limit
     * @param int $maxAttempts
     * @return array<int, array<string, mixed>>
     */
    public function previewPending(int $limit, int $maxAttempts = 0): array
    {
        if ($limit <= 0) {
            return [];
        }

        $select = $this->getConnection()->select()
            ->from($this->getTableName())
            ->where('status = ?', self::STATUS_PENDING)
            ->order(['missing_score DESC', 'product_id ASC', 'queue_id ASC'])
            ->limit($limit);
        if ($maxAttempts > 0) {
            $select->where('attempts < ?', $maxAttempts);
        }

        return $this->getConnection()->fetchAll($select);
    }

    /**
     * Return stale processing rows to pending status.
     *
     * @param int $olderThanSeconds
     * @return int
     */
    public function releaseStaleLocks(int $olderThanSeconds): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $olderThanSeconds));

        return $this->getConnection()->update(
            $this->getTableName(),
            [
                'status' => self::STATUS_PENDING,
                'locked_at' => null,
                'locked_by' => null,
                'updated_at' => $this->dateTime->gmtDate(),
            ],
            ['status = ?' => self::STATUS_PROCESSING, 'locked_at < ?' => $cutoff]
        );
    }

    /**
     * @param int $queueId
     * @param string[] $updatedFields
     * @param string $lockedBy
     * @return int
     */
    public function markDone(int $queueId, array $updatedFields, string $lockedBy): int
    {
        return $this->markTerminal($queueId, self::STATUS_DONE, null, $updatedFields, $lockedBy);
    }

    /**
     * @param int $queueId
     * @param string $error
     * @param string $lockedBy
     * @return int
     */
    public function markFailed(int $queueId, string $error, string $lockedBy): int
    {
        return $this->markTerminal($queueId, self::STATUS_FAILED, $error, [], $lockedBy);
    }

    /**
     * @param int $queueId
     * @param string $reason
     * @param string $lockedBy
     * @return int
     */
    public function markSkipped(int $queueId, string $reason, string $lockedBy): int
    {
        return $this->markTerminal($queueId, self::STATUS_SKIPPED, $reason, [], $lockedBy);
    }

    /**
     * Return one queue row by product ID.
     *
     * @param int $productId
     * @return array<string, mixed>|null
     */
    public function getByProductId(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        $select = $this->getConnection()->select()
            ->from($this->getTableName())
            ->where('product_id = ?', $productId)
            ->limit(1);

        $row = $this->getConnection()->fetchRow($select);

        return is_array($row) ? $row : null;
    }

    /**
     * Count queue rows by status.
     *
     * @return array<string, int>
     */
    public function getStatusCounts(): array
    {
        $select = $this->getConnection()->select()
            ->from($this->getTableName(), ['status', 'count' => new \Zend_Db_Expr('COUNT(*)')])
            ->group('status');

        $counts = [];
        foreach ($this->getConnection()->fetchPairs($select) as $status => $count) {
            $counts[$status] = (int) $count;
        }

        return $counts;
    }

    /**
     * Return pending queue rows for read-only status inspection.
     *
     * @param int $limit Use 0 to return all pending rows.
     * @return array<int, array<string, mixed>>
     */
    public function getPendingRows(int $limit = 100): array
    {
        $select = $this->getConnection()->select()
            ->from($this->getTableName())
            ->where('status = ?', self::STATUS_PENDING)
            ->order(['missing_score DESC', 'product_id ASC', 'queue_id ASC']);
        if ($limit > 0) {
            $select->limit($limit);
        }

        return $this->getConnection()->fetchAll($select);
    }

    /**
     * Remove existing queue rows, optionally limited to selected products.
     *
     * @param int[] $productIds
     * @return int
     */
    public function clear(array $productIds = []): int
    {
        $where = ['status != ?' => self::STATUS_PROCESSING];
        if (!empty($productIds)) {
            $where['product_id IN (?)'] = array_map('intval', $productIds);
        }

        return $this->getConnection()->delete($this->getTableName(), $where);
    }

    /**
     * Return failed queue rows to pending, guarded by attempts when supplied.
     *
     * @param int|null $maxAttempts
     * @return int
     */
    public function retryFailed(?int $maxAttempts = null): int
    {
        $where = ['status = ?' => self::STATUS_FAILED];
        if ($maxAttempts !== null) {
            $where['attempts <= ?'] = max(0, $maxAttempts);
        }

        return $this->getConnection()->update(
            $this->getTableName(),
            [
                'status' => self::STATUS_PENDING,
                'locked_at' => null,
                'locked_by' => null,
                'last_error' => null,
                'updated_at' => $this->dateTime->gmtDate(),
            ],
            $where
        );
    }

    /**
     * Return min/max pending score for operational status output.
     *
     * @return array{min: int|null, max: int|null}
     */
    public function getPendingScoreRange(): array
    {
        $select = $this->getConnection()->select()
            ->from($this->getTableName(), [
                'min_score' => new \Zend_Db_Expr('MIN(missing_score)'),
                'max_score' => new \Zend_Db_Expr('MAX(missing_score)'),
            ])
            ->where('status = ?', self::STATUS_PENDING);
        $row = $this->getConnection()->fetchRow($select) ?: [];

        return [
            'min' => $row['min_score'] === null ? null : (int) $row['min_score'],
            'max' => $row['max_score'] === null ? null : (int) $row['max_score'],
        ];
    }

    /**
     * Return current queue status keyed by product ID.
     *
     * @param int[] $productIds
     * @return array<int, string>
     */
    public function getStatusesByProductIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if (empty($productIds)) {
            return [];
        }

        $select = $this->getConnection()->select()
            ->from($this->getTableName(), ['product_id', 'status'])
            ->where('product_id IN (?)', $productIds);
        $statuses = [];
        foreach ($this->getConnection()->fetchPairs($select) as $productId => $status) {
            $statuses[(int) $productId] = (string) $status;
        }

        return $statuses;
    }

    /**
     * @param int $queueId
     * @param string $status
     * @param string|null $error
     * @param string[] $updatedFields
     * @param string $lockedBy
     * @return int
     */
    private function markTerminal(int $queueId, string $status, ?string $error, array $updatedFields, string $lockedBy): int
    {
        return $this->getConnection()->update(
            $this->getTableName(),
            [
                'status' => $status,
                'locked_at' => null,
                'locked_by' => null,
                'last_error' => $error,
                'updated_fields' => $this->json->serialize(array_values($updatedFields)),
                'updated_at' => $this->dateTime->gmtDate(),
                'processed_at' => $this->dateTime->gmtDate(),
            ],
            [
                'queue_id = ?' => $queueId,
                'status = ?' => self::STATUS_PROCESSING,
                'locked_by = ?' => $lockedBy,
            ]
        );
    }

    /**
     * Return processing product IDs keyed by product ID so enqueue cannot steal active leases.
     *
     * @param int[] $productIds
     * @return array<int, bool>
     */
    private function getProcessingProductIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (empty($productIds)) {
            return [];
        }

        $select = $this->getConnection()->select()
            ->from($this->getTableName(), ['product_id'])
            ->where('product_id IN (?)', $productIds)
            ->where('status = ?', self::STATUS_PROCESSING);

        $processing = [];
        foreach ($this->getConnection()->fetchCol($select) as $productId) {
            $processing[(int) $productId] = true;
        }

        return $processing;
    }

    /**
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    private function getRowsByIds(array $ids): array
    {
        $select = $this->getConnection()->select()
            ->from($this->getTableName())
            ->where('queue_id IN (?)', $ids)
            ->order(['missing_score DESC', 'product_id ASC', 'queue_id ASC']);

        return $this->getConnection()->fetchAll($select);
    }

    /**
     * @return AdapterInterface
     */
    private function getConnection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    /**
     * @return string
     */
    private function getTableName(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
