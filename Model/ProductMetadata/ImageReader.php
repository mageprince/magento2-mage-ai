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
use Magento\Catalog\Helper\Image as ProductImageHelper;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Downloadable\Api\LinkRepositoryInterface;
use Magento\Downloadable\Helper\Download;
use Magento\Downloadable\Helper\File as DownloadableFile;
use Magento\Downloadable\Model\Link as DownloadableLink;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Mageprince\MageAI\Model\Query\QueryException;

class ImageReader
{
    private const ANALYSIS_IMAGE_ID = 'product_page_image_medium_no_watermark';
    private const ANALYSIS_IMAGE_SIZE = 631;
    private const ANALYSIS_IMAGE_QUALITY = 90;
    private const MAX_INLINE_IMAGE_BYTES = 716800;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var MediaConfig
     */
    protected $mediaConfig;

    /**
     * @var LinkRepositoryInterface
     */
    protected $linkRepository;

    /**
     * @var DownloadableFile
     */
    protected $downloadableFile;

    /**
     * @var DownloadableLink
     */
    protected $downloadableLink;

    /**
     * @var ProductImageHelper
     */
    protected $productImageHelper;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @param Filesystem $filesystem
     * @param MediaConfig $mediaConfig
     * @param LinkRepositoryInterface $linkRepository
     * @param DownloadableFile $downloadableFile
     * @param DownloadableLink $downloadableLink
     * @param ProductImageHelper $productImageHelper
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Filesystem $filesystem,
        MediaConfig $mediaConfig,
        LinkRepositoryInterface $linkRepository,
        DownloadableFile $downloadableFile,
        DownloadableLink $downloadableLink,
        ProductImageHelper $productImageHelper,
        StoreManagerInterface $storeManager
    ) {
        $this->filesystem = $filesystem;
        $this->mediaConfig = $mediaConfig;
        $this->linkRepository = $linkRepository;
        $this->downloadableFile = $downloadableFile;
        $this->downloadableLink = $downloadableLink;
        $this->productImageHelper = $productImageHelper;
        $this->storeManager = $storeManager;
    }

    /**
     * Read the best available catalog product image for AI analysis.
     *
     * @param ProductInterface $product
     * @return array{data: string, mimeType: string, file: string}
     * @throws QueryException
     */
    public function read(ProductInterface $product): array
    {
        $imageFiles = $this->resolveProductImageFiles($product);
        if (!$imageFiles) {
            throw new QueryException(__('Product %1 has no image to analyze.', $product->getSku()));
        }

        try {
            $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            foreach ($imageFiles as $imageFile) {
                if (!$mediaDirectory->isExist($imageFile['path'])) {
                    continue;
                }

                $data = $mediaDirectory->readFile($imageFile['path']);
                if ($data === '' || $data === false) {
                    continue;
                }

                $image = $this->resolveAnalysisImage($product, $imageFile, $data, $mediaDirectory);

                return [
                    'data' => $image['data'],
                    'mimeType' => $this->resolveMimeType($image['file']),
                    'file' => $image['file'],
                ];
            }
        } catch (QueryException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new QueryException(__('Failed to read product image for %1: %2', $product->getSku(), $e->getMessage()));
        }

        throw new QueryException(__('Product %1 has no readable image to analyze.', $product->getSku()));
    }

    /**
     * Resolve candidate media paths from catalog images, then downloadable file links.
     *
     * @param ProductInterface $product
     * @return array<int, array{file: string, path: string, resizable: bool}>
     */
    private function resolveProductImageFiles(ProductInterface $product): array
    {
        $imageFiles = [];

        foreach (['image', 'small_image', 'thumbnail'] as $attributeCode) {
            $value = trim((string) $product->getData($attributeCode));
            if ($value !== '' && $value !== 'no_selection') {
                $this->addImageFile($imageFiles, $value, $this->mediaConfig->getMediaPath($value), true);
            }
        }

        foreach ((array) $product->getMediaGalleryEntries() as $entry) {
            $file = trim((string) $entry->getFile());
            if ($file !== '') {
                $this->addImageFile($imageFiles, $file, $this->mediaConfig->getMediaPath($file), true);
            }
        }

        foreach ($this->resolveDownloadableImageFiles($product) as $file) {
            $this->addImageFile($imageFiles, $file, $file, false);
        }

        return array_values($imageFiles);
    }

    /**
     * Resolve downloadable product file links as fallback source images.
     *
     * @param ProductInterface $product
     * @return string[]
     */
    private function resolveDownloadableImageFiles(ProductInterface $product): array
    {
        $files = [];

        try {
            $links = $this->linkRepository->getLinksByProduct($product);
        } catch (\Exception $e) {
            return $files;
        }

        foreach ((array) $links as $link) {
            if ((string) $link->getLinkType() !== Download::LINK_TYPE_FILE) {
                continue;
            }

            $linkFile = trim((string) $link->getLinkFile());
            if ($linkFile === '') {
                continue;
            }

            $extension = strtolower((string) pathinfo($linkFile, PATHINFO_EXTENSION));
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'avif'], true)) {
                continue;
            }

            $files[] = $this->downloadableFile->getFilePath($this->downloadableLink->getBasePath(), $linkFile);
        }

        return $files;
    }

    /**
     * Add a unique image file candidate.
     *
     * @param array<string, array{file: string, path: string, resizable: bool}> $imageFiles
     * @param string $file
     * @param string $path
     * @param bool $resizable
     * @return void
     */
    private function addImageFile(array &$imageFiles, string $file, string $path, bool $resizable): void
    {
        if (!isset($imageFiles[$path])) {
            $imageFiles[$path] = [
                'file' => $file,
                'path' => $path,
                'resizable' => $resizable,
            ];
        }
    }

    /**
     * Prefer a smaller generated catalog image when the selected image is too large to inline safely.
     *
     * @param ProductInterface $product
     * @param array{file: string, path: string, resizable: bool} $imageFile
     * @param string $data
     * @param ReadInterface $mediaDirectory
     * @return array{data: string, file: string}
     */
    private function resolveAnalysisImage(
        ProductInterface $product,
        array $imageFile,
        string $data,
        ReadInterface $mediaDirectory
    ): array {
        if (strlen($data) <= self::MAX_INLINE_IMAGE_BYTES || !$imageFile['resizable']) {
            return [
                'data' => $data,
                'file' => $imageFile['file'],
            ];
        }

        $resizedImage = $this->readResizedCatalogImage($product, $imageFile['file'], $mediaDirectory);
        if ($resizedImage !== null && strlen($resizedImage['data']) < strlen($data)) {
            return $resizedImage;
        }

        return [
            'data' => $data,
            'file' => $imageFile['file'],
        ];
    }

    /**
     * Generate and read the no-watermark product image cache file for image metadata analysis.
     *
     * @param ProductInterface $product
     * @param string $file
     * @param ReadInterface $mediaDirectory
     * @return array{data: string, file: string}|null
     */
    private function readResizedCatalogImage(
        ProductInterface $product,
        string $file,
        ReadInterface $mediaDirectory
    ): ?array {
        try {
            $helper = $this->productImageHelper->init(
                $product,
                self::ANALYSIS_IMAGE_ID,
                [
                    'type' => 'image',
                    'width' => self::ANALYSIS_IMAGE_SIZE,
                    'height' => self::ANALYSIS_IMAGE_SIZE,
                    'aspect_ratio' => true,
                    'frame' => false,
                ]
            );
            $helper->setImageFile($file)
                ->resize(self::ANALYSIS_IMAGE_SIZE, self::ANALYSIS_IMAGE_SIZE)
                ->setQuality(self::ANALYSIS_IMAGE_QUALITY)
                ->watermark('', null)
                ->save();

            $mediaPath = $this->resolveMediaPathFromUrl($helper->getUrl());
            if ($mediaPath === null || !$mediaDirectory->isExist($mediaPath)) {
                return null;
            }

            $data = $mediaDirectory->readFile($mediaPath);
            if ($data === '' || $data === false) {
                return null;
            }

            return [
                'data' => $data,
                'file' => $mediaPath,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Convert a product image media URL into a path relative to the media directory.
     *
     * @param string $url
     * @return string|null
     */
    private function resolveMediaPathFromUrl(string $url): ?string
    {
        try {
            $mediaBaseUrl = (string) $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
            if ($mediaBaseUrl !== '' && strpos($url, $mediaBaseUrl) === 0) {
                return $this->normalizeMediaPath(substr($url, strlen($mediaBaseUrl)));
            }
        } catch (\Exception $e) {
            // Fall back to parsing the URL path below.
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        $path = ltrim($path, '/');
        if (strpos($path, 'media/') === 0) {
            return $this->normalizeMediaPath(substr($path, strlen('media/')));
        }

        $mediaSegmentPosition = strpos($path, '/media/');
        if ($mediaSegmentPosition !== false) {
            return $this->normalizeMediaPath(substr($path, $mediaSegmentPosition + strlen('/media/')));
        }

        if (strpos($path, 'catalog/product/') === 0) {
            return $this->normalizeMediaPath($path);
        }

        return null;
    }

    /**
     * Normalize media-relative cache paths and ignore placeholders.
     *
     * @param string $path
     * @return string|null
     */
    private function normalizeMediaPath(string $path): ?string
    {
        $path = ltrim(strtok($path, '?') ?: '', '/');
        if ($path === '' || strpos($path, 'placeholder') !== false) {
            return null;
        }

        return $path;
    }

    /**
     * Resolve mime type from filename extension.
     *
     * @param string $file
     * @return string
     */
    private function resolveMimeType(string $file): string
    {
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'png':
                return 'image/png';
            case 'webp':
                return 'image/webp';
            case 'gif':
                return 'image/gif';
            default:
                return 'image/jpeg';
        }
    }
}
