<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */
// phpcs:disable Generic.Files.LineLength

namespace Mageprince\MageAI\Console;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Mageprince\MageAI\Helper\Data as HelperData;
use Mageprince\MageAI\Model\ProductMetadata\Queue\MissingDataScorer;
use Mageprince\MageAI\Model\ProductMetadata\Queue\QueueManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QueueImageMetadataCommand extends Command
{
    private const OPTION_REBUILD = 'rebuild';
    private const OPTION_APPEND = 'append';
    private const OPTION_STATUS = 'status';
    private const OPTION_LIST_PENDING = 'list-pending';
    private const OPTION_RETRY_FAILED = 'retry-failed';
    private const OPTION_RELEASE_STALE = 'release-stale';
    private const OPTION_MAX_ATTEMPTS = 'max-attempts';
    private const OPTION_PRODUCT_ID = 'product-id';
    private const OPTION_SKU = 'sku';
    private const OPTION_SKU_PREFIX = 'sku-prefix';
    private const OPTION_TYPE = 'type';
    private const OPTION_LIMIT = 'limit';
    private const OPTION_REPORT = 'report';
    private const OPTION_FORMAT = 'format';
    private const OPTION_MIN_SCORE = 'min-score';
    private const OPTION_INCLUDE_ZERO_SCORE = 'include-zero-score';
    private const CSV_DELIMITER = ',';
    private const CSV_ENCLOSURE = '"';
    private const CSV_ESCAPE = '\\';
    private const DEFAULT_PAGE_SIZE = 250;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var State
     */
    private $appState;

    /**
     * @var HelperData
     */
    private $helper;

    /**
     * @var MissingDataScorer
     */
    private $missingDataScorer;

    /**
     * @var QueueManager
     */
    private $queueManager;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @param CollectionFactory $collectionFactory
     * @param State $appState
     * @param HelperData $helper
     * @param MissingDataScorer $missingDataScorer
     * @param QueueManager $queueManager
     * @param DirectoryList $directoryList
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        State $appState,
        HelperData $helper,
        MissingDataScorer $missingDataScorer,
        QueueManager $queueManager,
        DirectoryList $directoryList
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->appState = $appState;
        $this->helper = $helper;
        $this->missingDataScorer = $missingDataScorer;
        $this->queueManager = $queueManager;
        $this->directoryList = $directoryList;
        parent::__construct();
    }

    /**
     * Configure command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('mageai:queue:image-metadata');
        $this->setDescription('Audit, build, inspect, and maintain the MageAI image metadata queue without AI calls.');
        $this->addOption(self::OPTION_REBUILD, null, InputOption::VALUE_NONE, 'Clear all non-processing queue rows, then rebuild rows for matched products.');
        $this->addOption(self::OPTION_APPEND, null, InputOption::VALUE_NONE, 'Append/upsert missing or changed matched products without clearing existing queue rows.');
        $this->addOption(self::OPTION_STATUS, null, InputOption::VALUE_NONE, 'Print queue counts by status and the pending score range.');
        $this->addOption(self::OPTION_LIST_PENDING, null, InputOption::VALUE_NONE, 'Include pending queue rows in status output; uses --limit rows, with 0 listing all pending rows.');
        $this->addOption(self::OPTION_RETRY_FAILED, null, InputOption::VALUE_NONE, 'Move failed rows back to pending.');
        $this->addOption(self::OPTION_RELEASE_STALE, null, InputOption::VALUE_OPTIONAL, 'Release processing locks older than MINUTES back to pending.', false);
        $this->addOption(self::OPTION_MAX_ATTEMPTS, null, InputOption::VALUE_OPTIONAL, 'Only retry failed rows with attempts less than or equal to this value.');
        $this->addOption(self::OPTION_PRODUCT_ID, null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Product ID(s) to audit or enqueue.');
        $this->addOption(self::OPTION_SKU, null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Product SKU(s) to audit or enqueue.');
        $this->addOption(self::OPTION_SKU_PREFIX, null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Product SKU prefix(es) to audit or enqueue, for example ksjas.');
        $this->addOption(self::OPTION_TYPE, null, InputOption::VALUE_OPTIONAL, 'Product type filter; use empty string for all product types.', 'image');
        $this->addOption(self::OPTION_LIMIT, null, InputOption::VALUE_OPTIONAL, 'Maximum products to include after filters; use 0 for all matched products.', 100);
        $this->addOption(self::OPTION_REPORT, null, InputOption::VALUE_OPTIONAL, 'Write CSV audit report path, relative to Magento root unless absolute.');
        $this->addOption(self::OPTION_FORMAT, null, InputOption::VALUE_OPTIONAL, 'Status output format: table or json.', 'table');
        $this->addOption(self::OPTION_MIN_SCORE, null, InputOption::VALUE_OPTIONAL, 'Only include products with this missing-data score or higher. Defaults to 1 unless --include-zero-score is set.');
        $this->addOption(self::OPTION_INCLUDE_ZERO_SCORE, null, InputOption::VALUE_NONE, 'Include zero-score products in queue/report output.');
    }

    /**
     * Execute command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->setAreaCode();

        if (!$this->helper->isEnabled()) {
            $output->writeln('<error>MageAI is disabled in configuration.</error>');
            return Command::FAILURE;
        }

        $format = strtolower((string) $input->getOption(self::OPTION_FORMAT));
        if (!in_array($format, ['table', 'json'], true)) {
            $output->writeln('<error>Invalid --format. Use table or json.</error>');
            return Command::FAILURE;
        }
        if (!$this->isValidNonNegativeIntegerOption($input->getOption(self::OPTION_MIN_SCORE))) {
            $output->writeln('<error>Invalid --min-score. Use a non-negative integer.</error>');
            return Command::FAILURE;
        }

        $changed = [];
        if ((bool) $input->getOption(self::OPTION_RETRY_FAILED)) {
            $maxAttempts = $input->getOption(self::OPTION_MAX_ATTEMPTS);
            $changed['retried_failed'] = $this->queueManager->retryFailed($maxAttempts === null ? null : (int) $maxAttempts);
        }

        $releaseStale = $input->getOption(self::OPTION_RELEASE_STALE);
        if ($releaseStale !== false) {
            $minutes = $releaseStale === null || $releaseStale === '' ? 60 : (int) $releaseStale;
            if ($minutes <= 0) {
                $output->writeln('<error>--release-stale minutes must be greater than zero.</error>');
                return Command::FAILURE;
            }
            $changed['released_stale'] = $this->queueManager->releaseStaleLocks($minutes * 60);
        }

        $shouldBuild = (bool) $input->getOption(self::OPTION_REBUILD) || (bool) $input->getOption(self::OPTION_APPEND) || (string) $input->getOption(self::OPTION_REPORT) !== '';
        $buildSummary = null;
        if ($shouldBuild) {
            $buildSummary = $this->buildQueueOrReport($input);
        }

        $pendingListLimit = (bool) $input->getOption(self::OPTION_LIST_PENDING)
            ? max(0, (int) $input->getOption(self::OPTION_LIMIT))
            : null;
        if ((bool) $input->getOption(self::OPTION_STATUS) || $pendingListLimit !== null || (!$shouldBuild && empty($changed))) {
            $this->writeStatus($output, $format, $changed, $buildSummary, $pendingListLimit);
            return Command::SUCCESS;
        }

        foreach ($changed as $label => $count) {
            $output->writeln(sprintf('<info>%s: %d row(s).</info>', str_replace('_', ' ', ucfirst($label)), $count));
        }
        if ($buildSummary !== null) {
            $this->writeBuildSummary($output, $buildSummary);
        }

        return Command::SUCCESS;
    }

    /**
     * Build queue rows and/or a CSV report without calling AI services.
     *
     * @param InputInterface $input
     * @return array<string, mixed>
     */
    private function buildQueueOrReport(InputInterface $input): array
    {
        $rebuild = (bool) $input->getOption(self::OPTION_REBUILD);
        $append = (bool) $input->getOption(self::OPTION_APPEND);
        $reportPath = (string) $input->getOption(self::OPTION_REPORT);
        $includeZeroScore = (bool) $input->getOption(self::OPTION_INCLUDE_ZERO_SCORE);
        $minScore = $this->getMinimumScore($input, $includeZeroScore);
        $limit = max(0, (int) $input->getOption(self::OPTION_LIMIT));
        $productIds = $this->normalizeIntArrayOption($input->getOption(self::OPTION_PRODUCT_ID));
        $skus = $this->normalizeArrayOption($input->getOption(self::OPTION_SKU));
        $skuPrefixes = $this->normalizeArrayOption($input->getOption(self::OPTION_SKU_PREFIX));
        $type = (string) $input->getOption(self::OPTION_TYPE);

        $reportHandle = null;
        if ($reportPath !== '') {
            $absoluteReportPath = $this->getAbsolutePath($reportPath);
            $this->ensureDirectory(dirname($absoluteReportPath));
            $reportHandle = fopen($absoluteReportPath, 'w');
            if ($reportHandle === false) {
                throw new \RuntimeException(sprintf('Unable to write report: %s', $absoluteReportPath));
            }
            fputcsv(
                $reportHandle,
                ['product_id', 'sku', 'type', 'missing_score', 'missing_fields', 'queue_status'],
                self::CSV_DELIMITER,
                self::CSV_ENCLOSURE,
                self::CSV_ESCAPE
            );
        }

        $clearedRows = 0;
        if ($rebuild) {
            // Rebuild intentionally replaces the queue contents for this command.
            // QueueManager::clear() preserves processing rows so active leases are not stolen.
            $clearedRows = $this->queueManager->clear();
        }

        $summary = [
            'scanned' => 0,
            'included' => 0,
            'cleared' => $clearedRows,
            'queued' => 0,
            'reported' => 0,
            'skipped_zero_score' => 0,
            'skipped_below_min_score' => 0,
            'min_score' => $minScore,
            'report' => $reportPath,
        ];

        $pageSize = $this->getPageSize($limit);
        $collection = $this->createCollection($productIds, $skus, $skuPrefixes, $type, $limit);
        $lastPage = (int) $collection->getLastPageNumber();
        $candidates = [];
        for ($page = 1; $page <= $lastPage; $page++) {
            $collection = $this->createCollection($productIds, $skus, $skuPrefixes, $type, $limit);
            $collection->setPageSize($pageSize);
            $collection->setCurPage($page);
            $collection->load();

            $pageProductIds = [];
            foreach ($collection as $product) {
                $pageProductIds[] = (int) $product->getId();
            }
            $statuses = $this->queueManager->getStatusesByProductIds($pageProductIds);

            foreach ($collection as $product) {
                $productId = (int) $product->getId();
                if ((int) $product->getStatus() !== ProductStatus::STATUS_ENABLED) {
                    continue;
                }

                $score = $this->missingDataScorer->score($product);
                $missingScore = (int) $score['score'];
                $missingFields = $score['fields'];
                $summary['scanned']++;

                if ($missingScore < $minScore) {
                    if ($missingScore <= 0) {
                        $summary['skipped_zero_score']++;
                    } else {
                        $summary['skipped_below_min_score']++;
                    }
                    continue;
                }

                $status = $statuses[$productId] ?? '';
                $candidates[] = [
                    'product_id' => $productId,
                    'sku' => (string) $product->getSku(),
                    'product_type' => (string) $product->getTypeId(),
                    'missing_score' => $missingScore,
                    'missing_fields' => $missingFields,
                    'status' => $status,
                ];
            }

            $collection->clear();
        }

        $selectedCandidatePool = ($rebuild || $append) ? $this->filterQueueableCandidates($candidates) : $candidates;
        $selectedCandidates = $this->limitCandidatesByPriority($selectedCandidatePool, $limit);
        $summary['included'] = count($selectedCandidates);
        if ($reportHandle !== null) {
            $this->writeCandidateReportRows($reportHandle, $selectedCandidates);
            $summary['reported'] = count($selectedCandidates);
        }

        if ($rebuild || $append) {
            $rows = $this->getQueueableCandidateRows($selectedCandidates);
            if (!empty($rows)) {
                $this->queueManager->enqueueRows($rows);
                $summary['queued'] = count($rows);
            }
        }

        if ($reportHandle !== null) {
            fclose($reportHandle);
        }

        return $summary;
    }

    /**
     * Sort candidates by processing priority and apply the requested inclusion limit.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private function limitCandidatesByPriority(array $candidates, int $limit): array
    {
        usort($candidates, function (array $left, array $right): int {
            if ((int) $left['missing_score'] !== (int) $right['missing_score']) {
                return (int) $right['missing_score'] <=> (int) $left['missing_score'];
            }

            return (int) $left['product_id'] <=> (int) $right['product_id'];
        });

        return $limit > 0 ? array_slice($candidates, 0, $limit) : $candidates;
    }

    /**
     * Write selected candidate rows to a CSV report.
     *
     * @param resource $reportHandle
     * @param array<int, array<string, mixed>> $candidates
     * @return void
     */
    private function writeCandidateReportRows($reportHandle, array $candidates): void
    {
        foreach ($candidates as $candidate) {
            fputcsv(
                $reportHandle,
                [
                    (int) $candidate['product_id'],
                    (string) $candidate['sku'],
                    (string) $candidate['product_type'],
                    (int) $candidate['missing_score'],
                    implode('|', $candidate['missing_fields']),
                    (string) ($candidate['status'] ?? ''),
                ],
                self::CSV_DELIMITER,
                self::CSV_ENCLOSURE,
                self::CSV_ESCAPE
            );
        }
    }

    /**
     * Keep only candidates that can be enqueued without stealing active processing leases.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function filterQueueableCandidates(array $candidates): array
    {
        return array_values(array_filter($candidates, function (array $candidate): bool {
            return ($candidate['status'] ?? '') !== QueueManager::STATUS_PROCESSING;
        }));
    }

    /**
     * Convert selected candidates to queue rows, skipping protected processing rows.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function getQueueableCandidateRows(array $candidates): array
    {
        $rows = [];
        foreach ($candidates as $candidate) {
            if (($candidate['status'] ?? '') === QueueManager::STATUS_PROCESSING) {
                continue;
            }

            $rows[] = [
                'product_id' => (int) $candidate['product_id'],
                'sku' => (string) $candidate['sku'],
                'product_type' => (string) $candidate['product_type'],
                'missing_score' => (int) $candidate['missing_score'],
                'missing_fields' => $candidate['missing_fields'],
            ];
        }

        return $rows;
    }

    /**
     * Create a product collection selecting only fields needed for scoring and reporting.
     *
     * @param int[] $productIds
     * @param string[] $skus
     * @param string[] $skuPrefixes
     * @param string $type
     * @param int $limit
     * @return Collection
     */
    private function createCollection(array $productIds, array $skus, array $skuPrefixes, string $type, int $limit): Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect($this->getAttributesToSelect());
        // Avoid filtering status in SQL here. MariaDB 10.6 can choose a very
        // slow index_merge plan for Magento's EAV status joins; status is
        // already selected and filtered in PHP while scoring.
        if (!empty($productIds)) {
            $collection->addFieldToFilter('entity_id', ['in' => $productIds]);
        }
        $this->addSkuFilters($collection, $skus, $skuPrefixes);
        if ($type !== '') {
            $collection->addFieldToFilter('type_id', $type);
        }
        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize($this->getPageSize($limit));

        return $collection;
    }

    /**
     * Add exact and prefix SKU filters to a product collection.
     *
     * @param Collection $collection
     * @param string[] $skus
     * @param string[] $skuPrefixes
     * @return void
     */
    private function addSkuFilters(Collection $collection, array $skus, array $skuPrefixes): void
    {
        $conditions = [];
        if (!empty($skus)) {
            $conditions[] = ['in' => $skus];
        }
        foreach ($skuPrefixes as $skuPrefix) {
            $conditions[] = ['like' => $this->escapeLikePrefix($skuPrefix) . '%'];
        }
        if (empty($conditions)) {
            return;
        }

        $collection->addAttributeToFilter('sku', count($conditions) === 1 ? reset($conditions) : $conditions);
    }

    /**
     * Escape literal SKU prefix wildcards before using a SQL LIKE suffix match.
     *
     * @param string $skuPrefix
     * @return string
     */
    private function escapeLikePrefix(string $skuPrefix): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $skuPrefix);
    }

    /**
     * @return string[]
     */
    private function getAttributesToSelect(): array
    {
        return array_values(array_unique(array_merge(
            ['name', 'image', 'small_image', 'thumbnail', 'status'],
            array_keys($this->helper->getProductImageAnalysisAttributeConfig())
        )));
    }

    /**
     * @param OutputInterface $output
     * @param string $format
     * @param array<string, int> $changed
     * @param array<string, mixed>|null $buildSummary
     * @param int|null $pendingListLimit
     * @return void
     */
    private function writeStatus(
        OutputInterface $output,
        string $format,
        array $changed = [],
        ?array $buildSummary = null,
        ?int $pendingListLimit = null
    ): void
    {
        $counts = $this->queueManager->getStatusCounts();
        $scoreRange = $this->queueManager->getPendingScoreRange();
        $pendingRows = $pendingListLimit === null ? [] : $this->queueManager->getPendingRows($pendingListLimit);
        if ($format === 'json') {
            $statusData = [
                'counts' => $counts,
                'pending_score_range' => $scoreRange,
                'changed' => $changed,
                'build' => $buildSummary,
            ];
            if ($pendingListLimit !== null) {
                $statusData['pending_rows'] = array_map(function (array $row): array {
                    return $this->formatPendingRowForJson($row);
                }, $pendingRows);
            }
            $output->writeln(json_encode($statusData, JSON_PRETTY_PRINT));
            return;
        }

        $table = new Table($output);
        $table->setHeaders(['Status', 'Count']);
        foreach ([QueueManager::STATUS_PENDING, QueueManager::STATUS_PROCESSING, QueueManager::STATUS_DONE, QueueManager::STATUS_FAILED, QueueManager::STATUS_SKIPPED] as $status) {
            $table->addRow([$status, $counts[$status] ?? 0]);
        }
        $table->render();

        $range = $scoreRange['max'] === null ? 'none' : sprintf('%d-%d', $scoreRange['min'], $scoreRange['max']);
        $output->writeln(sprintf('<info>Pending score range: %s</info>', $range));
        if ($pendingListLimit !== null) {
            $this->writePendingRows($output, $pendingRows, $pendingListLimit);
        }
        foreach ($changed as $label => $count) {
            $output->writeln(sprintf('<info>%s: %d row(s).</info>', str_replace('_', ' ', ucfirst($label)), $count));
        }
        if ($buildSummary !== null) {
            $this->writeBuildSummary($output, $buildSummary);
        }
    }

    /**
     * @param OutputInterface $output
     * @param array<int, array<string, mixed>> $rows
     * @param int $limit
     * @return void
     */
    private function writePendingRows(OutputInterface $output, array $rows, int $limit): void
    {
        $limitLabel = $limit === 0 ? 'all' : (string) $limit;
        if (empty($rows)) {
            $output->writeln(sprintf('<info>Pending products (limit: %s): none</info>', $limitLabel));
            return;
        }

        $output->writeln(sprintf('<info>Pending products (limit: %s):</info>', $limitLabel));
        $table = new Table($output);
        $table->setHeaders(['Queue ID', 'Product ID', 'SKU', 'Type', 'Score', 'Missing Fields', 'Attempts', 'Created At', 'Updated At']);
        foreach ($rows as $row) {
            $missingFields = $this->decodeJsonList($row['missing_fields'] ?? null);
            $table->addRow([
                (int) ($row['queue_id'] ?? 0),
                (int) ($row['product_id'] ?? 0),
                (string) ($row['sku'] ?? ''),
                (string) ($row['product_type'] ?? ''),
                (int) ($row['missing_score'] ?? 0),
                empty($missingFields) ? '-' : implode(', ', $missingFields),
                (int) ($row['attempts'] ?? 0),
                (string) ($row['created_at'] ?? ''),
                (string) ($row['updated_at'] ?? ''),
            ]);
        }
        $table->render();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatPendingRowForJson(array $row): array
    {
        $row['queue_id'] = (int) ($row['queue_id'] ?? 0);
        $row['product_id'] = (int) ($row['product_id'] ?? 0);
        $row['missing_score'] = (int) ($row['missing_score'] ?? 0);
        $row['attempts'] = (int) ($row['attempts'] ?? 0);
        $row['missing_fields'] = $this->decodeJsonList($row['missing_fields'] ?? null);

        return $row;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private function decodeJsonList($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [$value];
        }

        $items = [];
        foreach ($decoded as $item) {
            if (is_scalar($item)) {
                $items[] = (string) $item;
            }
        }

        return $items;
    }

    /**
     * @param OutputInterface $output
     * @param array<string, mixed> $summary
     * @return void
     */
    private function writeBuildSummary(OutputInterface $output, array $summary): void
    {
        $output->writeln(sprintf(
            '<info>Queue audit complete. Scanned: %d, included: %d, cleared: %d, queued: %d, reported: %d, below min-score skipped: %d, zero-score skipped: %d.</info>',
            $summary['scanned'],
            $summary['included'],
            $summary['cleared'],
            $summary['queued'],
            $summary['reported'],
            $summary['skipped_below_min_score'],
            $summary['skipped_zero_score']
        ));
        $output->writeln(sprintf('<info>Minimum score: %d</info>', $summary['min_score']));
        if (!empty($summary['report'])) {
            $output->writeln(sprintf('<info>Report: %s</info>', $summary['report']));
        }
    }

    /**
     * Resolve the effective minimum score for queue/report inclusion.
     *
     * @param InputInterface $input
     * @param bool $includeZeroScore
     * @return int
     */
    private function getMinimumScore(InputInterface $input, bool $includeZeroScore): int
    {
        $minScore = $input->getOption(self::OPTION_MIN_SCORE);
        if ($minScore === null || $minScore === '') {
            return $includeZeroScore ? 0 : 1;
        }

        return max(0, (int) $minScore);
    }

    /**
     * Validate nullable non-negative integer command option values.
     *
     * @param mixed $value
     * @return bool
     */
    private function isValidNonNegativeIntegerOption($value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_scalar($value) && preg_match('/^\d+$/', (string) $value) === 1;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private function normalizeArrayOption($value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }
        $items = [];
        foreach ($value as $item) {
            foreach (explode(',', (string) $item) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $items[] = $part;
                }
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @param mixed $value
     * @return int[]
     */
    private function normalizeIntArrayOption($value): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $this->normalizeArrayOption($value)))));
    }

    /**
     * @param string $path
     * @return string
     */
    private function getAbsolutePath(string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        return rtrim($this->directoryList->getRoot(), '/') . '/' . ltrim($path, '/');
    }

    /**
     * @param string $directory
     * @return void
     */
    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create report directory: %s', $directory));
        }
    }

    /**
     * @param int $limit
     * @return int
     */
    private function getPageSize(int $limit): int
    {
        if ($limit > 0) {
            return min($limit, self::DEFAULT_PAGE_SIZE);
        }

        return self::DEFAULT_PAGE_SIZE;
    }

    /**
     * Set adminhtml area for product collection and EAV operations.
     *
     * @return void
     */
    private function setAreaCode(): void
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException $e) {
            return;
        }
    }
}
