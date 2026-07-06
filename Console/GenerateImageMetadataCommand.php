<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */
// phpcs:disable Generic.Files.LineLength

namespace Mageprince\MageAI\Console;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Mageprince\MageAI\Helper\Data as HelperData;
use Mageprince\MageAI\Model\Cache\FlushSkipper;
use Mageprince\MageAI\Model\ProductMetadata\ImageAnalyzer;
use Mageprince\MageAI\Model\ProductMetadata\MetadataApplier;
use Mageprince\MageAI\Model\ProductMetadata\Queue\QueueManager;
use Mageprince\MageAI\Model\Query\QueryException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateImageMetadataCommand extends Command
{
    private const OPTION_PRODUCT_ID = 'product-id';
    private const OPTION_SKU = 'sku';
    private const OPTION_LIMIT = 'limit';
    private const OPTION_FORCE = 'force';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_TYPE = 'type';
    private const OPTION_FROM_QUEUE = 'from-queue';
    private const OPTION_BATCH_SIZE = 'batch-size';
    private const OPTION_WORKER = 'worker';
    private const OPTION_MAX_RUNTIME = 'max-runtime';
    private const OPTION_SLEEP = 'sleep';
    private const OPTION_STALE_AFTER = 'stale-after';
    private const OPTION_MAX_ATTEMPTS = 'max-attempts';
    private const OPTION_SKIP_CACHE_FLUSH = 'skip-cache-flush';
    private const DEBUG_BASE64_PREVIEW_LENGTH = 96;

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var ProductAction
     */
    protected $productAction;

    /**
     * @var State
     */
    protected $appState;

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
     * @var QueueManager
     */
    protected $queueManager;

    /**
     * @var FlushSkipper
     */
    protected $cacheFlushSkipper;

    /**
     * @param CollectionFactory $collectionFactory
     * @param ProductRepositoryInterface $productRepository
     * @param ProductAction $productAction
     * @param State $appState
     * @param HelperData $helper
     * @param ImageAnalyzer $imageAnalyzer
     * @param MetadataApplier $metadataApplier
     * @param QueueManager $queueManager
     * @param FlushSkipper|null $cacheFlushSkipper
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        ProductRepositoryInterface $productRepository,
        ProductAction $productAction,
        State $appState,
        HelperData $helper,
        ImageAnalyzer $imageAnalyzer,
        MetadataApplier $metadataApplier,
        QueueManager $queueManager,
        ?FlushSkipper $cacheFlushSkipper = null
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->productRepository = $productRepository;
        $this->productAction = $productAction;
        $this->appState = $appState;
        $this->helper = $helper;
        $this->imageAnalyzer = $imageAnalyzer;
        $this->metadataApplier = $metadataApplier;
        $this->queueManager = $queueManager;
        $this->cacheFlushSkipper = $cacheFlushSkipper ?: ObjectManager::getInstance()->get(FlushSkipper::class);
        parent::__construct();
    }

    /**
     * Configure command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('mageai:generate:image-metadata');
        $this->setDescription('Generate configured product attributes from product images using an OpenAI-compatible endpoint. Use -vvv to print native Ollama request and response bodies.');
        $this->addOption(self::OPTION_PRODUCT_ID, null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Product ID(s) to process.');
        $this->addOption(self::OPTION_SKU, null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Product SKU(s) to process.');
        $this->addOption(self::OPTION_LIMIT, null, InputOption::VALUE_OPTIONAL, 'Maximum products to process when no product-id/sku filter is supplied. Use 0 for all matched products.', 50);
        $this->addOption(self::OPTION_TYPE, null, InputOption::VALUE_OPTIONAL, 'Product type to process when no product-id/sku filter is supplied. Use empty string for all types.', 'image');
        $this->addOption(self::OPTION_FORCE, 'f', InputOption::VALUE_NONE, 'Overwrite existing configured image-analysis attributes.');
        $this->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Analyze and report changes without saving products or creating attribute options.');
        $this->addOption(self::OPTION_FROM_QUEUE, null, InputOption::VALUE_NONE, 'Process image metadata queue rows instead of direct product filters.');
        $this->addOption(self::OPTION_BATCH_SIZE, null, InputOption::VALUE_OPTIONAL, 'Queue rows to claim per batch.', 25);
        $this->addOption(self::OPTION_WORKER, null, InputOption::VALUE_OPTIONAL, 'Queue lock owner name. Defaults to hostname and process ID.');
        $this->addOption(self::OPTION_MAX_RUNTIME, null, InputOption::VALUE_OPTIONAL, 'Stop gracefully after this many seconds. Use 0 for no limit.', 0);
        $this->addOption(self::OPTION_SLEEP, null, InputOption::VALUE_OPTIONAL, 'Seconds to sleep between queue batches.', 0);
        $this->addOption(self::OPTION_STALE_AFTER, null, InputOption::VALUE_OPTIONAL, 'Release processing locks older than this many minutes before claiming. Use 0 to leave them untouched.', 0);
        $this->addOption(self::OPTION_MAX_ATTEMPTS, null, InputOption::VALUE_OPTIONAL, 'Do not claim pending rows that have reached this attempt count. Use 0 for unlimited attempts.', 0);
        $this->addOption(self::OPTION_SKIP_CACHE_FLUSH, null, InputOption::VALUE_NONE, 'Skip cache clean/flush/invalidation calls while processing queue rows. Use with --from-queue for faster bulk generation, then flush cache once after the run if needed.');
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

        $force = (bool) $input->getOption(self::OPTION_FORCE);
        $dryRun = (bool) $input->getOption(self::OPTION_DRY_RUN);
        $debugLogger = $this->getImageAnalyzerDebugLogger($output);
        $fromQueue = (bool) $input->getOption(self::OPTION_FROM_QUEUE);
        $skipCacheFlush = (bool) $input->getOption(self::OPTION_SKIP_CACHE_FLUSH);

        if ($fromQueue) {
            return $this->executeFromQueue($input, $output, $force, $dryRun, $skipCacheFlush);
        }

        if ($skipCacheFlush) {
            $output->writeln('<error>The --skip-cache-flush option is only supported with --from-queue.</error>');
            return Command::FAILURE;
        }

        $productIds = $this->normalizeArrayOption($input->getOption(self::OPTION_PRODUCT_ID));
        $skus = $this->normalizeArrayOption($input->getOption(self::OPTION_SKU));
        $ids = $this->getProductIds($productIds, $skus, (string) $input->getOption(self::OPTION_TYPE), (int) $input->getOption(self::OPTION_LIMIT));

        if (empty($ids)) {
            $output->writeln('<comment>No products matched the requested filters.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Processing %d product(s)%s.</info>', count($ids), $dryRun ? ' (dry run)' : ''));

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($ids as $productId) {
            $processed++;
            try {
                $product = $this->productRepository->getById((int) $productId, false, 0, true);
                $product->setStoreId(0);

                if (!$force && $this->metadataApplier->hasGeneratedMetadata($product)) {
                    $skipped++;
                    $output->writeln(sprintf('[%d/%d] Skipped %s: metadata already populated.', $processed, count($ids), $product->getSku()));
                    continue;
                }

                $metadata = $this->imageAnalyzer->analyze(
                    $product,
                    $debugLogger,
                    $this->resolvePreviousProductNameContext((int) $product->getId())
                );
                $changes = $this->metadataApplier->apply($product, $metadata, $force, $dryRun);

                if (empty($changes)) {
                    $skipped++;
                    $output->writeln(sprintf('[%d/%d] Skipped %s: no applicable changes.', $processed, count($ids), $product->getSku()));
                    continue;
                }

                if (!$dryRun) {
                    $this->persistAttributeChanges($product, $changes);
                    $this->assertAttributeChangesPersisted($product, $changes);
                }

                $updated++;
                $output->writeln(sprintf('[%d/%d] %s %s: %s', $processed, count($ids), $dryRun ? 'Would update' : 'Updated', $product->getSku(), implode(', ', array_keys($changes))));
            } catch (QueryException $e) {
                $failed++;
                $output->writeln(sprintf('[%d/%d] <error>Failed product ID %s: %s</error>', $processed, count($ids), $productId, $e->getMessage()));
            } catch (\Exception $e) {
                $failed++;
                $output->writeln(sprintf('[%d/%d] <error>Failed product ID %s: %s</error>', $processed, count($ids), $productId, $e->getMessage()));
            }
        }

        $output->writeln(sprintf('<info>Done. Processed: %d, updated: %d, skipped: %d, failed: %d.</info>', $processed, $updated, $skipped, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Process queued rows using atomic claims, or preview rows in dry-run mode.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param bool $force
     * @param bool $dryRun
     * @param bool $skipCacheFlush
     * @return int
     */
    private function executeFromQueue(InputInterface $input, OutputInterface $output, bool $force, bool $dryRun, bool $skipCacheFlush): int
    {
        if (!$skipCacheFlush) {
            return $this->processQueue($input, $output, $force, $dryRun);
        }

        $output->writeln('<comment>Skipping cache clean/flush/invalidation calls during queue processing.</comment>');

        return $this->cacheFlushSkipper->run(function () use ($input, $output, $force, $dryRun): int {
            return $this->processQueue($input, $output, $force, $dryRun);
        });
    }

    /**
     * Process queued rows using atomic claims, or preview rows in dry-run mode.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param bool $force
     * @param bool $dryRun
     * @return int
     */
    private function processQueue(InputInterface $input, OutputInterface $output, bool $force, bool $dryRun): int
    {
        $batchSize = max(1, (int) $input->getOption(self::OPTION_BATCH_SIZE));
        $worker = trim((string) $input->getOption(self::OPTION_WORKER));
        $worker = $worker !== '' ? $worker : $this->getDefaultWorkerId();
        $maxRuntime = max(0, (int) $input->getOption(self::OPTION_MAX_RUNTIME));
        $sleep = max(0, (int) $input->getOption(self::OPTION_SLEEP));
        $staleAfter = max(0, (int) $input->getOption(self::OPTION_STALE_AFTER));
        $maxAttempts = max(0, (int) $input->getOption(self::OPTION_MAX_ATTEMPTS));
        $startedAt = time();

        if ($staleAfter > 0 && !$dryRun) {
            $released = $this->queueManager->releaseStaleLocks($staleAfter * 60);
            if ($released > 0) {
                $output->writeln(sprintf('<comment>Released %d stale queue lock(s).</comment>', $released));
            }
        }

        $output->writeln(sprintf('<info>Processing image metadata queue as %s%s.</info>', $worker, $dryRun ? ' (dry run preview)' : ''));

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        do {
            $rows = $dryRun
                ? $this->queueManager->previewPending($batchSize, $maxAttempts)
                : $this->queueManager->claim($batchSize, $worker, $maxAttempts);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $processed++;
                $result = $this->processQueueRow($row, $processed, $output, $force, $dryRun);
                if ($result === 'updated') {
                    $updated++;
                } elseif ($result === 'skipped') {
                    $skipped++;
                } else {
                    $failed++;
                }
            }

            if ($dryRun) {
                break;
            }
            if ($this->isRuntimeExceeded($startedAt, $maxRuntime)) {
                $output->writeln('<comment>Maximum runtime reached; exiting after current claimed batch.</comment>');
                break;
            }
            if ($sleep > 0 && !$this->isRuntimeExceeded($startedAt, $maxRuntime)) {
                sleep($sleep);
            }
        } while (!$this->isRuntimeExceeded($startedAt, $maxRuntime));

        $output->writeln(sprintf('<info>Queue done. Processed: %d, updated: %d, skipped: %d, failed: %d.</info>', $processed, $updated, $skipped, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Return a raw transport logger for image-analysis requests in debug verbosity.
     *
     * @param OutputInterface $output
     * @return callable|null
     */
    private function getImageAnalyzerDebugLogger(OutputInterface $output): ?callable
    {
        if ($output->getVerbosity() < OutputInterface::VERBOSITY_DEBUG) {
            return null;
        }

        return function (string $label, string $content) use ($output): void {
            $output->writeln(sprintf('<comment>--- MageAI %s ---</comment>', $label));
            $output->writeln($this->truncateDebugImageContent($label, $content), OutputInterface::OUTPUT_RAW);
            $output->writeln('');
        };
    }

    /**
     * Truncate inline image data before printing verbose request payloads.
     *
     * The HTTP request itself still uses the full base64 string; only the -vvv
     * console copy is shortened so logs remain readable.
     *
     * @param string $label
     * @param string $content
     * @return string
     */
    private function truncateDebugImageContent(string $label, string $content): string
    {
        if (stripos($label, 'request') === false) {
            return $content;
        }

        $parts = explode("\n\n", $content, 2);
        if (count($parts) !== 2) {
            return $content;
        }

        $payload = json_decode($parts[1], true);
        if (!is_array($payload)) {
            return $content;
        }

        $json = json_encode($this->truncateDebugPayloadValue($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return $content;
        }

        return $parts[0] . "\n\n" . $json;
    }

    /**
     * Recursively truncate OpenAI image URLs and Ollama images arrays.
     *
     * @param mixed $value
     * @return mixed
     */
    private function truncateDebugPayloadValue($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ($key === 'images' && is_array($item)) {
                    foreach ($item as $index => $image) {
                        $value[$key][$index] = is_string($image) ? $this->truncateDebugBase64($image) : $image;
                    }
                    continue;
                }

                $value[$key] = $this->truncateDebugPayloadValue($item);
            }

            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        $base64Marker = ';base64,';
        $base64Position = strpos($value, $base64Marker);
        if (strpos($value, 'data:') === 0 && $base64Position !== false) {
            $prefixLength = $base64Position + strlen($base64Marker);
            return substr($value, 0, $prefixLength) . $this->truncateDebugBase64(substr($value, $prefixLength));
        }

        return $value;
    }

    /**
     * @param string $base64
     * @return string
     */
    private function truncateDebugBase64(string $base64): string
    {
        $length = strlen($base64);
        if ($length <= self::DEBUG_BASE64_PREVIEW_LENGTH) {
            return $base64;
        }

        return substr($base64, 0, self::DEBUG_BASE64_PREVIEW_LENGTH)
            . sprintf('... [truncated %d base64 chars]', $length - self::DEBUG_BASE64_PREVIEW_LENGTH);
    }

    /**
     * Analyze and apply one queue row.
     *
     * @param array<string, mixed> $row
     * @param int $processed
     * @param OutputInterface $output
     * @param bool $force
     * @param bool $dryRun
     * @return string
     */
    private function processQueueRow(array $row, int $processed, OutputInterface $output, bool $force, bool $dryRun): string
    {
        $queueId = (int) $row['queue_id'];
        $productId = (int) $row['product_id'];
        $lockedBy = (string) ($row['locked_by'] ?? '');
        $startedAt = microtime(true);

        try {
            $product = $this->productRepository->getById($productId, false, 0, true);
            $product->setStoreId(0);
            if ($this->isProductDisabled($product)) {
                $duration = number_format(microtime(true) - $startedAt, 2);
                if (!$dryRun) {
                    $this->assertQueueLeaseUpdated(
                        $this->queueManager->markSkipped($queueId, 'Product is disabled.', $lockedBy),
                        $queueId
                    );
                }
                $output->writeln(sprintf('[%d] Queue #%d skipped product %d (%s) in %ss: product is disabled.', $processed, $queueId, $productId, $product->getSku(), $duration));
                return 'skipped';
            }

            $metadata = $this->imageAnalyzer->analyze(
                $product,
                $this->getImageAnalyzerDebugLogger($output),
                $this->resolvePreviousProductNameContext($productId)
            );
            $changes = $this->metadataApplier->apply($product, $metadata, $force, $dryRun);
            $duration = number_format(microtime(true) - $startedAt, 2);

            if (empty($changes)) {
                if (!$dryRun) {
                    $this->assertQueueLeaseUpdated(
                        $this->queueManager->markSkipped($queueId, 'No applicable changes remain.', $lockedBy),
                        $queueId
                    );
                }
                $output->writeln(sprintf('[%d] Queue #%d skipped product %d (%s) in %ss: no applicable changes.', $processed, $queueId, $productId, $product->getSku(), $duration));
                return 'skipped';
            }

            if (!$dryRun) {
                $this->persistAttributeChanges($product, $changes);
                $this->assertAttributeChangesPersisted($product, $changes);
                $this->assertQueueLeaseUpdated(
                    $this->queueManager->markDone($queueId, array_keys($changes), $lockedBy),
                    $queueId
                );
            }

            $output->writeln(sprintf('[%d] Queue #%d %s product %d (%s) in %ss: %s', $processed, $queueId, $dryRun ? 'would update' : 'updated', $productId, $product->getSku(), $duration, implode(', ', array_keys($changes))));
            return 'updated';
        } catch (QueryException $e) {
            return $this->handleQueueFailure($queueId, $productId, $lockedBy, $processed, $output, $dryRun, $e);
        } catch (\Exception $e) {
            return $this->handleQueueFailure($queueId, $productId, $lockedBy, $processed, $output, $dryRun, $e);
        }
    }

    /**
     * Treat a zero-row terminal update as a lost worker lease.
     *
     * @param int $updatedRows
     * @param int $queueId
     * @return void
     * @throws LocalizedException
     */
    private function assertQueueLeaseUpdated(int $updatedRows, int $queueId): void
    {
        if ($updatedRows <= 0) {
            throw new LocalizedException(__('Queue row %1 lease was lost before status update.', $queueId));
        }
    }

    /**
     * Persist generated attributes at admin/default scope without a full product save.
     *
     * A repository save processes media gallery entries and can delete existing image
     * rows when a CLI-loaded product does not carry a complete media payload. The
     * metadata generator only updates EAV attributes, so save those values directly.
     *
     * @param ProductInterface $product
     * @param array<string, mixed> $changes
     * @return void
     */
    private function persistAttributeChanges(ProductInterface $product, array $changes): void
    {
        $attributes = $this->getAttributeUpdateValues($product, $changes);
        if (empty($attributes)) {
            return;
        }

        $this->productAction->updateAttributes([(int) $product->getId()], $attributes, 0);
    }

    /**
     * Fail loudly if generated metadata was reported changed but did not persist.
     *
     * @param ProductInterface $product
     * @param array<string, mixed> $changes
     * @return void
     * @throws LocalizedException
     */
    private function assertAttributeChangesPersisted(ProductInterface $product, array $changes): void
    {
        $attributes = $this->getAttributeUpdateValues($product, $changes);
        if (empty($attributes)) {
            return;
        }

        $productId = (int) $product->getId();
        $savedProduct = $this->productRepository->getById($productId, false, 0, true);
        $missing = [];
        foreach ($attributes as $attributeCode => $expectedValue) {
            $actualValue = $this->normalizeAttributeUpdateValue($savedProduct->getData($attributeCode));
            if ($actualValue !== $expectedValue) {
                $missing[] = $attributeCode;
            }
        }

        if (!empty($missing)) {
            throw new LocalizedException(__(
                'Generated metadata did not persist for: %1',
                implode(', ', $missing)
            ));
        }
    }

    /**
     * Return changed product data values suitable for default-scope EAV persistence.
     *
     * @param ProductInterface $product
     * @param array<string, mixed> $changes
     * @return array<string, string>
     */
    private function getAttributeUpdateValues(ProductInterface $product, array $changes): array
    {
        $attributes = [];
        foreach (array_keys($changes) as $attributeCode) {
            if (!$product->hasData($attributeCode)) {
                continue;
            }

            $value = $this->normalizeAttributeUpdateValue($product->getData($attributeCode));
            if ($value !== '') {
                $attributes[$attributeCode] = $value;
            }
        }

        return $attributes;
    }

    /**
     * Normalize an attribute value for ProductAction persistence and verification.
     *
     * @param mixed $value
     * @return string
     */
    private function normalizeAttributeUpdateValue($value): string
    {
        if (is_array($value)) {
            return implode(',', array_values(array_filter(array_map(static function ($part) {
                return trim((string) $part);
            }, $value), 'strlen')));
        }

        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * Resolve context from the product whose ID immediately precedes the current product ID.
     *
     * @param int $productId
     * @return string
     */
    private function resolvePreviousProductNameContext(int $productId): string
    {
        $previousProductId = $productId - 1;
        if ($previousProductId <= 0) {
            return '';
        }

        try {
            $previousProduct = $this->productRepository->getById($previousProductId, false, 0, true);
        } catch (\Exception $e) {
            return '';
        }

        $name = trim((string) $previousProduct->getName());
        return $name === '' ? '' : mb_substr($name, 0, 160);
    }

    /**
     * Record and log queue row failures.
     *
     * @param int $queueId
     * @param int $productId
     * @param string $lockedBy
     * @param int $processed
     * @param OutputInterface $output
     * @param bool $dryRun
     * @param \Exception $e
     * @return string
     */
    private function handleQueueFailure(int $queueId, int $productId, string $lockedBy, int $processed, OutputInterface $output, bool $dryRun, \Exception $e): string
    {
        $message = $this->summarizeError($e->getMessage());
        if (!$dryRun) {
            $updatedRows = $this->queueManager->markFailed($queueId, $message, $lockedBy);
            if ($updatedRows <= 0) {
                $output->writeln(sprintf('[%d] <error>Queue #%d failed product %d after its worker lease was lost: %s</error>', $processed, $queueId, $productId, $message));
                return 'failed';
            }
        }
        $output->writeln(sprintf('[%d] <error>Queue #%d failed product %d: %s</error>', $processed, $queueId, $productId, $message));

        return 'failed';
    }

    /**
     * @return string
     */
    private function getDefaultWorkerId(): string
    {
        $host = gethostname();
        return sprintf('%s:%d', $host ?: 'mageai-worker', getmypid());
    }

    /**
     * @param int $startedAt
     * @param int $maxRuntime
     * @return bool
     */
    private function isRuntimeExceeded(int $startedAt, int $maxRuntime): bool
    {
        return $maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime;
    }

    /**
     * @param string $message
     * @return string
     */
    private function summarizeError(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message));
        return mb_substr($message, 0, 1000);
    }

    /**
     * Set adminhtml area for product repository and EAV operations.
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

    /**
     * Build product ID list from CLI filters.
     *
     * @param string[] $productIds
     * @param string[] $skus
     * @param string $type
     * @param int $limit
     * @return int[]
     */
    private function getProductIds(array $productIds, array $skus, string $type, int $limit): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(array_unique(array_merge(
            ['name', 'image', 'small_image', 'thumbnail', 'status'],
            array_keys($this->helper->getProductImageAnalysisAttributes())
        )));
        $collection->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);

        if (!empty($productIds)) {
            $collection->addFieldToFilter('entity_id', ['in' => $productIds]);
        }
        if (!empty($skus)) {
            $collection->addAttributeToFilter('sku', ['in' => $skus]);
        }
        if (empty($productIds) && empty($skus)) {
            if ($type !== '') {
                $collection->addFieldToFilter('type_id', $type);
            }
            if ($limit > 0) {
                $collection->setPageSize($limit);
                $collection->setCurPage(1);
            }
        }

        $collection->load();
        return array_map('intval', $collection->getColumnValues('entity_id'));
    }

    /**
     * @param ProductInterface $product
     * @return bool
     */
    private function isProductDisabled(ProductInterface $product): bool
    {
        return (int) $product->getStatus() !== ProductStatus::STATUS_ENABLED;
    }

    /**
     * Normalize repeatable option values.
     *
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
}
