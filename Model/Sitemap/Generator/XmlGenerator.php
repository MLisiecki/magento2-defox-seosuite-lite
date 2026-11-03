<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki 
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */
declare(strict_types=1);

namespace Defox\SEOSuite\Model\Sitemap\Generator;

use Defox\SEOSuite\Helper\Config;
use Defox\SEOSuite\Logger\Logger;
use Defox\SEOSuite\Model\Sitemap\SitemapGeneratorInterface;
use Defox\SEOSuite\Model\Sitemap\SitemapProviderPool;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\File\WriteInterface as FileWriteInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * XML sitemap generator
 *
 * Streams items batch by batch into per-store files "<code>_sitemap[_N|_index].xml[.gz]" (Config::getSitemapFileName):
 * - Multiple files (50,000 URLs / 50 MB uncompressed per file) + sitemap index
 * - Gzip compression (streamed)
 * - Hreflang tags, image sitemaps
 * Files are written under "<name>.tmp" and renamed only when the whole set is complete;
 * the previously published set stays untouched until then.
 */
class XmlGenerator implements SitemapGeneratorInterface
{
    /**
     * Maximum URLs per sitemap file
     */
    private const MAX_URLS_PER_FILE = 50000;

    /**
     * Maximum file size (50MB uncompressed)
     */
    private const MAX_FILE_SIZE = 52428800; // 50MB

    /**
     * Items requested from a provider per call
     */
    private const BATCH_SIZE = 1000;

    /**
     * Suffix of not-yet-published files (same directory, so the final rename is atomic)
     */
    private const TMP_SUFFIX = '.tmp';

    /**
     * XML namespace for sitemap
     */
    private const XML_NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /**
     * XML namespace for image sitemap
     */
    private const XML_NAMESPACE_IMAGE = 'http://www.google.com/schemas/sitemap-image/1.1';

    /**
     * XML namespace for hreflang
     */
    private const XML_NAMESPACE_XHTML = 'http://www.w3.org/1999/xhtml';

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var Logger
     */
    private Logger $logger;

    /**
     * @var SitemapProviderPool
     */
    private SitemapProviderPool $providerPool;

    /**
     * @var Filesystem
     */
    private Filesystem $filesystem;

    /**
     * @var EventManager
     */
    private EventManager $eventManager;

    /**
     * @var DateTime
     */
    private DateTime $dateTime;

    /**
     * @var WriteInterface
     */
    private ?WriteInterface $directory = null;

    /**
     * @var array
     */
    private array $generationStats = [];

    /**
     * @var array
     */
    private array $generationOptions = [];

    /**
     * Final names (without TMP_SUFFIX) of the files written in this run, in publish order
     *
     * @var string[]
     */
    private array $tempFiles = [];

    /**
     * File being written (one at a time)
     *
     * @var FileWriteInterface|null
     */
    private ?FileWriteInterface $stream = null;

    /**
     * @var \DeflateContext|null
     */
    private ?\DeflateContext $deflate = null;

    /**
     * @var int URLs in the current file
     */
    private int $fileUrls = 0;

    /**
     * @var int Uncompressed bytes in the current file
     */
    private int $fileBytes = 0;

    /**
     * Constructor
     *
     * @param Config $config
     * @param Logger $logger
     * @param SitemapProviderPool $providerPool
     * @param Filesystem $filesystem
     * @param EventManager $eventManager
     * @param DateTime $dateTime
     */
    public function __construct(
        Config $config,
        Logger $logger,
        SitemapProviderPool $providerPool,
        Filesystem $filesystem,
        EventManager $eventManager,
        DateTime $dateTime
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->providerPool = $providerPool;
        $this->filesystem = $filesystem;
        $this->eventManager = $eventManager;
        $this->dateTime = $dateTime;
    }

    /**
     * @inheritDoc
     */
    public function generate(int $storeId, array $options = []): array
    {
        $startTime = microtime(true);
        $this->generationOptions = $options;
        $this->generationStats = [
            'start_time' => $this->dateTime->gmtDate(),
            'store_id' => $storeId,
            'files' => [],
            'total_urls' => 0,
            'errors' => []
        ];
        $this->tempFiles = [];
        $path = '';

        try {
            $this->logger->info(sprintf('Starting sitemap generation for store %d', $storeId));

            $path = $this->config->getSitemapPath($storeId);
            $compress = (bool)$this->config->getValue('defox_seosuite/sitemap/enable_compression', $storeId);

            $files = $this->writeSitemapFiles($storeId, $path, $compress);

            if ($this->generationStats['total_urls'] === 0) {
                throw new LocalizedException(__('No items found for sitemap generation'));
            }

            if (count($files) > 1) {
                array_unshift($files, $this->writeIndex($storeId, $path, $compress, $files));
            }

            $this->publish($storeId, $path);

            $directory = $this->getDirectory();
            $generatedFiles = array_map(
                static fn (string $name): string => $directory->getAbsolutePath($path . $name),
                $files
            );

            // Validate sitemaps if enabled
            if (isset($options['validate_after_generation']) && $options['validate_after_generation']) {
                $this->validateGeneratedFiles($generatedFiles);
            }

            $this->generationStats['end_time'] = $this->dateTime->gmtDate();
            $this->generationStats['duration'] = microtime(true) - $startTime;

            $this->logger->info(sprintf(
                'Sitemap generation completed for store %d. Generated %d files with %d URLs in %.2f seconds',
                $storeId,
                count($generatedFiles),
                $this->generationStats['total_urls'],
                $this->generationStats['duration']
            ));

            return $generatedFiles;
        } catch (\Throwable $e) {
            $this->discardTempFiles($path);
            $this->logger->error('Error generating sitemap: ' . $e->getMessage(), ['exception' => $e]);
            $this->generationStats['errors'][] = $e->getMessage();

            // Send error notification if enabled
            if ($this->config->getValue('defox_seosuite/sitemap/generation_error_notification', $storeId)) {
                $this->sendErrorNotification($storeId, $e);
            }

            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function getGenerationStats(): array
    {
        return $this->generationStats;
    }

    /**
     * @inheritDoc
     */
    public function getSitemapUrl(int $storeId, string $fileName): string
    {
        return $this->config->getSitemapUrl($storeId, $fileName);
    }

    /**
     * @inheritDoc
     */
    public function validateSitemap(string $filePath): array
    {
        $result = ['valid' => false, 'errors' => []];
        // Collect libxml errors instead of PHP warnings (whose text includes the file path)
        $useInternalErrors = libxml_use_internal_errors(true);

        try {
            if (!file_exists($filePath)) {
                $result['errors'][] = 'File does not exist';
                return $result;
            }

            // Load XML
            $xml = new \DOMDocument();
            if (!$xml->load($filePath, LIBXML_NONET)) {
                $error = libxml_get_last_error();
                $result['errors'][] = 'Failed to load XML document: '
                    . ($error ? trim($error->message) . ' (line ' . $error->line . ')' : 'unknown error');
                return $result;
            }

            // Check URL count
            $urls = $xml->getElementsByTagName('url');
            if ($urls->length > self::MAX_URLS_PER_FILE) {
                $result['errors'][] = sprintf(
                    'Too many URLs: %d (max: %d)',
                    $urls->length,
                    self::MAX_URLS_PER_FILE
                );
            }
            
            // Check file size
            $fileSize = filesize($filePath);
            if ($fileSize > self::MAX_FILE_SIZE) {
                $result['errors'][] = sprintf(
                    'File too large: %s (max: %s)',
                    $this->formatBytes($fileSize),
                    $this->formatBytes(self::MAX_FILE_SIZE)
                );
            }
            
            if (empty($result['errors'])) {
                $result['valid'] = true;
            }
        } catch (\Exception $e) {
            $result['errors'][] = 'Validation error: ' . $e->getMessage();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }

        return $result;
    }


    /**
     * Stream all provider items into temp sitemap files
     *
     * Providers are paged with (limit, offset); a page may be an array or any iterable (e.g. a generator), so nothing
     * beyond one page is held in memory. A provider exception aborts the run (the published set stays as it was).
     *
     * @param int $storeId
     * @param string $path Sitemap directory relative to pub/
     * @param bool $compress
     * @return string[] Final file names, in order
     */
    private function writeSitemapFiles(int $storeId, string $path, bool $compress): array
    {
        $enabledProviders = $this->providerPool->getEnabledProviders($storeId);

        if (empty($enabledProviders)) {
            $this->logger->warning(sprintf(
                'XmlGenerator: No enabled providers found for store %d',
                $storeId
            ));
            return [];
        }

        $names = [];
        $urlsetOpen = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL
            . '<urlset xmlns="' . self::XML_NAMESPACE . '" xmlns:image="' . self::XML_NAMESPACE_IMAGE
            . '" xmlns:xhtml="' . self::XML_NAMESPACE_XHTML . '">' . PHP_EOL;
        $urlsetClose = '</urlset>' . PHP_EOL;

        foreach ($enabledProviders as $provider) {
            $offset = 0;
            do {
                $count = 0;
                foreach ($provider->getItems($storeId, self::BATCH_SIZE, $offset) as $item) {
                    $count++;
                    if (!$item->isIncluded()) {
                        continue;
                    }

                    $urlXml = $this->generateUrlXml($item);

                    if ($this->stream !== null
                        && ($this->fileUrls >= self::MAX_URLS_PER_FILE
                            || $this->fileBytes + strlen($urlXml) + strlen($urlsetClose) > self::MAX_FILE_SIZE)
                    ) {
                        $this->closeFile($urlsetClose);
                    }

                    if ($this->stream === null) {
                        $suffix = $names ? '_' . (count($names) + 1) : '';
                        $names[] = $this->openFile(
                            $path,
                            $this->config->getSitemapFileName($storeId, $suffix, $compress),
                            $compress,
                            $urlsetOpen
                        );
                    }

                    $this->writeChunk($urlXml);
                    $this->fileUrls++;
                    $this->generationStats['total_urls']++;
                }
                $offset += self::BATCH_SIZE;
            } while ($count === self::BATCH_SIZE);
        }

        if ($this->stream !== null) {
            $this->closeFile($urlsetClose);
        }

        return $names;
    }

    /**
     * Write the temp sitemap index listing the given files
     *
     * @param int $storeId
     * @param string $path
     * @param bool $compress
     * @param string[] $fileNames
     * @return string Final index file name
     */
    private function writeIndex(int $storeId, string $path, bool $compress, array $fileNames): string
    {
        $indexName = $this->openFile(
            $path,
            $this->config->getSitemapFileName($storeId, '_index', $compress),
            $compress,
            '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL
                . '<sitemapindex xmlns="' . self::XML_NAMESPACE . '">' . PHP_EOL
        );
        $lastmod = $this->dateTime->gmtDate('Y-m-d\TH:i:s\Z');

        foreach ($fileNames as $fileName) {
            $this->writeChunk(
                '  <sitemap>' . PHP_EOL
                . '    <loc>' . htmlspecialchars($this->config->getSitemapUrl($storeId, $fileName)) . '</loc>' . PHP_EOL
                . '    <lastmod>' . $lastmod . '</lastmod>' . PHP_EOL
                . '  </sitemap>' . PHP_EOL
            );
        }

        $this->closeFile('</sitemapindex>' . PHP_EOL);

        return $indexName;
    }

    /**
     * Open "<name>.tmp" for writing and write the header
     *
     * @param string $path
     * @param string $fileName Final name
     * @param bool $compress
     * @param string $header
     * @return string $fileName
     */
    private function openFile(string $path, string $fileName, bool $compress, string $header): string
    {
        $this->tempFiles[] = $fileName;
        $this->stream = $this->getDirectory()->openFile($path . $fileName . self::TMP_SUFFIX, 'w');
        $this->deflate = $compress ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]) : null;
        $this->fileUrls = 0;
        $this->fileBytes = 0;
        $this->writeChunk($header);

        return $fileName;
    }

    /**
     * Append to the current file (gzip-compressed on the fly when enabled)
     *
     * @param string $data
     * @return void
     */
    private function writeChunk(string $data): void
    {
        $this->fileBytes += strlen($data);
        $this->stream->write($this->deflate ? deflate_add($this->deflate, $data, ZLIB_NO_FLUSH) : $data);
    }

    /**
     * Write the footer, close the current file and record its stats
     *
     * @param string $footer
     * @return void
     */
    private function closeFile(string $footer): void
    {
        $this->writeChunk($footer);
        if ($this->deflate) {
            $this->stream->write(deflate_add($this->deflate, '', ZLIB_FINISH));
        }
        $this->stream->close();
        $this->stream = null;
        $this->deflate = null;

        $this->generationStats['files'][] = [
            'name' => end($this->tempFiles),
            'size' => $this->fileBytes,
            'urls' => $this->fileUrls
        ];
    }

    /**
     * Rename the complete temp set onto the published names (index last), then delete this store's stale files
     *
     * @param int $storeId
     * @param string $path
     * @return void
     */
    private function publish(int $storeId, string $path): void
    {
        $directory = $this->getDirectory();
        $published = $this->tempFiles;

        foreach ($published as $fileName) {
            $directory->renameFile($path . $fileName . self::TMP_SUFFIX, $path . $fileName);
        }
        $this->tempFiles = [];

        try {
            $deleted = 0;
            foreach ($directory->read($path === '' ? null : rtrim($path, '/')) as $file) {
                $fileName = basename($file);
                if ($this->config->isSitemapFileOfStore($storeId, $fileName)
                    && !in_array($fileName, $published, true)
                    && $directory->isFile($file)
                ) {
                    $directory->delete($file);
                    $deleted++;
                }
            }
            $this->logger->info(sprintf('Removed %d stale sitemap files of store %d', $deleted, $storeId));
        } catch (\Exception $e) {
            // The new set is already live; a leftover old part file is harmless
            $this->logger->warning('Error removing stale sitemap files: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Close and delete this run's unpublished temp files
     *
     * @param string $path
     * @return void
     */
    private function discardTempFiles(string $path): void
    {
        $directory = $this->getDirectory();

        try {
            if ($this->stream !== null) {
                $this->stream->close();
            }
        } catch (\Exception $e) {
            $this->logger->warning('Error closing sitemap temp file: ' . $e->getMessage());
        }
        $this->stream = null;
        $this->deflate = null;

        foreach ($this->tempFiles as $fileName) {
            try {
                if ($directory->isFile($path . $fileName . self::TMP_SUFFIX)) {
                    $directory->delete($path . $fileName . self::TMP_SUFFIX);
                }
            } catch (\Exception $e) {
                $this->logger->warning('Error removing sitemap temp file: ' . $e->getMessage());
            }
        }
        $this->tempFiles = [];
    }

    /**
     * Generate XML for a single URL
     *
     * @param \Defox\SEOSuite\Model\Sitemap\SitemapItemInterface $item
     * @return string
     */
    private function generateUrlXml($item): string
    {
        $xml = '  <url>' . PHP_EOL;
        $xml .= '    <loc>' . htmlspecialchars($item->getUrl()) . '</loc>' . PHP_EOL;
        
        if ($item->getLastmod()) {
            $xml .= '    <lastmod>' . $item->getLastmod() . '</lastmod>' . PHP_EOL;
        }
        
        if ($item->getChangefreq()) {
            $xml .= '    <changefreq>' . $item->getChangefreq() . '</changefreq>' . PHP_EOL;
        }
        
        if ($item->getPriority() !== null) {
            $xml .= '    <priority>' . number_format($item->getPriority(), 1) . '</priority>' . PHP_EOL;
        }
        
        // Add alternate language versions (if enabled in options)
        $includeHreflang = isset($this->generationOptions['include_hreflang']) 
        ? $this->generationOptions['include_hreflang'] 
        : $this->config->getValue('defox_seosuite/sitemap/enable_hreflang', $this->generationStats['store_id']);
        
        if ($includeHreflang) {
        foreach ($item->getAlternates() as $alternate) {
                $xml .= sprintf(
                    '    <xhtml:link rel="alternate" hreflang="%s" href="%s" />' . PHP_EOL,
                    htmlspecialchars($alternate['lang']),
                    htmlspecialchars($alternate['url'])
            );
        }
        }
        
        // Add images (if enabled in options)
        $includeImages = isset($this->generationOptions['include_images']) 
        ? $this->generationOptions['include_images'] 
        : $this->config->getValue('defox_seosuite/sitemap/product/include_images', $this->generationStats['store_id']);
        
        if ($includeImages) {
        foreach ($item->getImages() as $image) {
            $xml .= '    <image:image>' . PHP_EOL;
                $xml .= '      <image:loc>' . htmlspecialchars($image['url']) . '</image:loc>' . PHP_EOL;
                    
                    if (!empty($image['title'])) {
                        $xml .= '      <image:title>' . htmlspecialchars($image['title']) . '</image:title>' . PHP_EOL;
                    }
                    
                    if (!empty($image['caption'])) {
                        $xml .= '      <image:caption>' . htmlspecialchars($image['caption']) . '</image:caption>' . PHP_EOL;
                    }
                    
                    $xml .= '    </image:image>' . PHP_EOL;
                }
            }
        
        $xml .= '  </url>' . PHP_EOL;
        
        return $xml;
    }


    /**
     * Send error notification
     *
     * @param int $storeId
     * @param \Exception $exception
     * @return void
     */
    private function sendErrorNotification(int $storeId, \Throwable $exception): void
    {
        try {
            $this->eventManager->dispatch('defox_seosuite_sitemap_generation_error', [
                'store_id' => $storeId,
                'exception' => $exception,
                'stats' => $this->generationStats
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error sending error notification: ' . $e->getMessage());
        }
    }

    /**
     * Get directory
     *
     * @return WriteInterface
     */
    private function getDirectory(): WriteInterface
    {
        if ($this->directory === null) {
            $this->directory = $this->filesystem->getDirectoryWrite(DirectoryList::PUB);
        }
        
        return $this->directory;
    }

    /**
     * Validate generated files
     *
     * @param array $generatedFiles
     * @return void
     */
    private function validateGeneratedFiles(array $generatedFiles): void
    {
        foreach ($generatedFiles as $filePath) {
            $validation = $this->validateSitemap($filePath);
            if (!$validation['valid']) {
                $this->logger->warning(sprintf(
                    'Sitemap validation failed for %s: %s',
                    basename($filePath),
                    implode(', ', $validation['errors'])
                ));
                $this->generationStats['errors'][] = sprintf(
                    'Validation failed for %s: %s',
                    basename($filePath),
                    implode(', ', $validation['errors'])
                );
            } else {
                $this->logger->info(sprintf(
                    'Sitemap validation passed for %s',
                    basename($filePath)
                ));
            }
        }
    }

    /**
     * Format bytes to human readable size
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
