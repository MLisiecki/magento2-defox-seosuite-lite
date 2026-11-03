<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */
declare(strict_types=1);

namespace Defox\SEOSuite\Controller\Adminhtml\Sitemap;

use Defox\SEOSuite\Helper\Config;
use Defox\SEOSuite\Model\SafeErrorMessage;
use Defox\SEOSuite\Model\Sitemap\Validator\AdvancedXmlValidator;
use Defox\SEOSuite\Model\Sitemap\Validator\ValidationResult;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Sitemap validation controller
 * 
 * Provides comprehensive sitemap validation including:
 * - File upload validation
 * - URL-based validation  
 * - Multi-level validation (XSD, Google rules, SEO best practices)
 * - Detailed validation reports
 * - Progress tracking for large files
 */
class Validate extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    /**
     * Authorization level of a basic admin session
     */
    public const ADMIN_RESOURCE = 'Defox_SEOSuite::sitemap_validate';

    /**
     * Maximum file size for upload (50MB)
     */
    private const MAX_FILE_SIZE = 52428800;

    /**
     * Allowed file extensions
     */
    private const ALLOWED_EXTENSIONS = ['xml', 'gz'];

    /**
     * Staging subdirectory of var/tmp for files under validation
     */
    private const STAGING_DIR = 'sitemap_validation';

    /**
     * @var PageFactory
     */
    private PageFactory $resultPageFactory;

    /**
     * @var JsonFactory
     */
    private JsonFactory $resultJsonFactory;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var AdvancedXmlValidator
     */
    private AdvancedXmlValidator $validator;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var SafeErrorMessage
     */
    private SafeErrorMessage $safeErrorMessage;

    /**
     * @var Filesystem
     */
    private Filesystem $filesystem;

    /**
     * @var Curl
     */
    private Curl $curl;

    /**
     * Constructor
     *
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param JsonFactory $resultJsonFactory
     * @param Config $config
     * @param AdvancedXmlValidator $validator
     * @param StoreManagerInterface $storeManager
     * @param Filesystem $filesystem
     * @param Curl $curl
     * @param SafeErrorMessage $safeErrorMessage
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        JsonFactory $resultJsonFactory,
        Config $config,
        AdvancedXmlValidator $validator,
        StoreManagerInterface $storeManager,
        Filesystem $filesystem,
        Curl $curl,
        SafeErrorMessage $safeErrorMessage
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->config = $config;
        $this->validator = $validator;
        $this->storeManager = $storeManager;
        $this->filesystem = $filesystem;
        $this->curl = $curl;
        $this->safeErrorMessage = $safeErrorMessage;
    }

    /**
     * Execute action
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        // GET only renders the page; every action (and its form_key check) requires POST
        if (!$this->getRequest()->isPost()) {
            return $this->showValidationForm();
        }

        if ($this->getRequest()->getParam('ajax_action')) {
            return $this->handleAjaxRequest();
        }

        return $this->processValidation();
    }

    /**
     * Show validation form
     *
     * @return ResultInterface
     */
    private function showValidationForm(): ResultInterface
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Defox_SEOSuite::sitemap_validate');
        $resultPage->getConfig()->getTitle()->prepend(__('Sitemap Management - Validate'));
        
        return $resultPage;
    }

    /**
     * Process validation request
     *
     * @return ResultInterface
     */
    private function processValidation(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();
        
        try {
            $validationType = $this->getRequest()->getParam('validation_type');
            $validationOptions = $this->getValidationOptions();
            
            switch ($validationType) {
                case 'file_upload':
                    $validationResult = $this->validateUploadedFile($validationOptions);
                    break;
                    
                case 'url_validation':
                    $url = (string)$this->getRequest()->getParam('sitemap_url');
                    $validationResult = $this->validateSitemapFromUrl($url, $validationOptions);
                    break;
                    
                case 'current_sitemap':
                    $storeId = (int)$this->getRequest()->getParam('store_id');
                    $validationResult = $this->validateCurrentSitemap($storeId, $validationOptions);
                    break;
                    
                default:
                    throw new LocalizedException(__('Invalid validation type'));
            }

            return $result->setData([
                'success' => true,
                'validation_result' => $this->formatValidationResult($validationResult),
                'recommendations' => $this->generateRecommendations($validationResult)
            ]);
            
        } catch (\Exception $e) {
            $message = $this->safeErrorMessage->get($e);
            return $result->setData([
                'success' => false,
                'message' => __('Validation failed: %1', $message),
                'error' => $message
            ]);
        }
    }

    /**
     * Handle AJAX requests
     *
     * @return ResultInterface
     */
    private function handleAjaxRequest(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();
        $action = $this->getRequest()->getParam('ajax_action');
        
        try {
            switch ($action) {
                case 'get_current_sitemaps':
                    return $result->setData($this->getCurrentSitemaps());
                    
                case 'validate_url':
                    $url = (string)$this->getRequest()->getParam('url');
                    return $result->setData($this->checkUrlAccessibility($url));
                    
                default:
                    throw new LocalizedException(__('Invalid AJAX action: %1', $action));
            }
            
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'error' => $this->safeErrorMessage->get($e)
            ]);
        }
    }

    /**
     * Validate uploaded file
     *
     * @param array $options
     * @return ValidationResult
     * @throws LocalizedException
     */
    private function validateUploadedFile(array $options): ValidationResult
    {
        $file = $this->getRequest()->getFiles('sitemap_file');
        if (!is_array($file)
            || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string)$file['tmp_name'])
        ) {
            throw new LocalizedException(__('File upload failed. Please select a sitemap file.'));
        }

        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new LocalizedException(__('Only XML and GZ files are allowed.'));
        }

        if ((int)$file['size'] > self::MAX_FILE_SIZE) {
            throw new LocalizedException(__('File size exceeds maximum limit of %1MB', self::MAX_FILE_SIZE / 1024 / 1024));
        }

        // PHP's own upload temp file is removed by PHP at the end of the request
        return $this->validateStagedCopy((string)$file['tmp_name'], $options);
    }

    /**
     * Validate sitemap from URL
     *
     * @param string $url
     * @param array $options
     * @return ValidationResult
     * @throws LocalizedException
     */
    private function validateSitemapFromUrl(string $url, array $options): ValidationResult
    {
        $this->assertOwnStoreUrl($url);

        $this->curl->setTimeout(30);
        $this->curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $this->curl->setOption(CURLOPT_MAXFILESIZE, self::MAX_FILE_SIZE);
        try {
            $this->curl->get($url);
        } catch (\Exception $e) {
            throw new LocalizedException(__('Unable to access sitemap URL: %1', $e->getMessage()));
        }

        $httpCode = $this->curl->getStatus();
        if ($httpCode !== 200) {
            throw new LocalizedException(__('Unable to access sitemap URL. HTTP status: %1', $httpCode));
        }

        $content = $this->curl->getBody();
        if ($content === '') {
            throw new LocalizedException(__('Empty response from sitemap URL'));
        }

        $tmpDir = $this->filesystem->getDirectoryWrite(DirectoryList::TMP);
        $download = self::STAGING_DIR . '/' . bin2hex(random_bytes(16)) . '.download';
        try {
            $tmpDir->writeFile($download, $content);
            $validationResult = $this->validateStagedCopy($tmpDir->getAbsolutePath($download), $options);
        } finally {
            if ($tmpDir->isExist($download)) {
                $tmpDir->delete($download);
            }
        }

        $validationResult->addMetadata('source_url', $url);
        $validationResult->addMetadata('content_length', strlen($content));
        $validationResult->addMetadata('http_status', $httpCode);

        return $validationResult;
    }

    /**
     * Validate current sitemap
     *
     * @param int $storeId
     * @param array $options
     * @return ValidationResult
     * @throws LocalizedException
     */
    private function validateCurrentSitemap(int $storeId, array $options): ValidationResult
    {
        $store = $this->storeManager->getStore($storeId);
        $fileName = $this->config->getPublishedSitemapFileName($storeId);

        if ($fileName === null) {
            throw new LocalizedException(__('Sitemap file not found for store %1. Generate sitemap first.', $store->getName()));
        }
        $filePath = $this->config->getFullSitemapPath($storeId) . $fileName;

        $validationResult = $this->validateStagedCopy($filePath, $options);

        // Add store-specific information
        $validationResult->addMetadata('store_id', $storeId);
        $validationResult->addMetadata('store_name', $store->getName());
        $validationResult->addMetadata('file_size', filesize($filePath));
        $validationResult->addMetadata('last_modified', filemtime($filePath));
        $validationResult->addMetadata('is_compressed', pathinfo($filePath, PATHINFO_EXTENSION) === 'gz');

        return $validationResult;
    }

    /**
     * Get validation options from request
     *
     * @return array
     */
    private function getValidationOptions(): array
    {
        return [
            'validate_xsd' => (bool)$this->getRequest()->getParam('validate_xsd', true),
            'validate_google_rules' => (bool)$this->getRequest()->getParam('validate_google_rules', true),
            'validate_seo_best_practices' => (bool)$this->getRequest()->getParam('validate_seo_best_practices', true),
            'check_url_accessibility' => (bool)$this->getRequest()->getParam('check_url_accessibility', false),
            'max_urls_to_check' => (int)$this->getRequest()->getParam('max_urls_to_check', 10),
            'timeout_per_url' => (int)$this->getRequest()->getParam('timeout_per_url', 5),
            'follow_redirects' => (bool)$this->getRequest()->getParam('follow_redirects', true),
            'check_response_time' => (bool)$this->getRequest()->getParam('check_response_time', true),
            'validate_images' => (bool)$this->getRequest()->getParam('validate_images', false),
            'strict_mode' => (bool)$this->getRequest()->getParam('strict_mode', false)
        ];
    }

    /**
     * Copy a (possibly gzipped) sitemap to var/tmp under a random name, validate it, always remove the copy
     *
     * @param string $source
     * @param array $options
     * @return ValidationResult
     * @throws LocalizedException
     */
    private function validateStagedCopy(string $source, array $options): ValidationResult
    {
        $staged = $this->stageXml($source);
        try {
            return $this->validator->validateComprehensive($staged, $options);
        } finally {
            unlink($staged);
        }
    }

    /**
     * Stream-decompress $source into var/tmp/sitemap_validation/<random>.xml, capped at MAX_FILE_SIZE bytes
     *
     * gzread() passes non-gzip input through unchanged, so plain XML is simply copied.
     *
     * @param string $source
     * @return string Absolute path of the staged file
     * @throws LocalizedException
     */
    private function stageXml(string $source): string
    {
        $tmpDir = $this->filesystem->getDirectoryWrite(DirectoryList::TMP);
        $tmpDir->create(self::STAGING_DIR);
        $target = $tmpDir->getAbsolutePath(self::STAGING_DIR . '/' . bin2hex(random_bytes(16)) . '.xml');

        $in = gzopen($source, 'rb');
        if ($in === false) {
            throw new LocalizedException(__('Unable to read the sitemap file.'));
        }
        $out = fopen($target, 'xb');
        if ($out === false) {
            gzclose($in);
            throw new LocalizedException(__('Unable to create a temporary file.'));
        }

        $complete = false;
        $written = 0;
        try {
            while (!gzeof($in)) {
                $chunk = gzread($in, 1048576);
                if ($chunk === false) {
                    throw new LocalizedException(__('Failed to decompress gzipped file'));
                }
                $written += strlen($chunk);
                if ($written > self::MAX_FILE_SIZE) {
                    throw new LocalizedException(
                        __('Uncompressed sitemap exceeds maximum size of %1MB', self::MAX_FILE_SIZE / 1024 / 1024)
                    );
                }
                fwrite($out, $chunk);
            }
            $complete = true;
        } finally {
            gzclose($in);
            fclose($out);
            if (!$complete) {
                unlink($target);
            }
        }

        return $target;
    }

    /**
     * SSRF guard used by every outbound request: http(s) only, host:port must be a store base URL host:port
     *
     * @param string $url
     * @return void
     * @throws LocalizedException
     */
    private function assertOwnStoreUrl(string $url): void
    {
        $allowed = [];
        foreach ($this->storeManager->getStores() as $store) {
            $allowed[] = $this->getHostPort((string)$store->getBaseUrl(UrlInterface::URL_TYPE_WEB, false));
            $allowed[] = $this->getHostPort((string)$store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true));
        }

        $hostPort = $this->getHostPort($url);
        if ($hostPort === null || !in_array($hostPort, $allowed, true)) {
            throw new LocalizedException(__('Only URLs on this store\'s own domains can be checked.'));
        }
    }

    /**
     * Normalised "host:port" of an http(s) URL, or null if the URL is not acceptable
     *
     * @param string $url
     * @return string|null
     */
    private function getHostPort(string $url): ?string
    {
        if (preg_match('/[\x00-\x20\\\\]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        return strtolower($parts['host']) . ':' . ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    /**
     * Get current sitemaps for all stores
     *
     * @return array
     */
    private function getCurrentSitemaps(): array
    {
        $sitemaps = [];
        
        foreach ($this->storeManager->getStores() as $store) {
            if (!$store->getIsActive()) {
                continue;
            }
            
            $storeId = (int)$store->getId();

            try {
                $fileName = $this->config->getPublishedSitemapFileName($storeId);
                $filePath = $fileName === null ? null : $this->config->getFullSitemapPath($storeId) . $fileName;
                $url = $this->config->getSitemapUrl(
                    $storeId,
                    $fileName ?? $this->config->getSitemapFileName($storeId)
                );
            } catch (LocalizedException $e) {
                // Invalid stored path: list the store without a file instead of failing the whole listing
                $fileName = $filePath = null;
                $url = '';
            }

            $exists = $filePath !== null;
            
            $sitemapInfo = [
                'store_id' => $storeId,
                'store_name' => $store->getName(),
                'store_code' => $store->getCode(),
                'exists' => $exists,
                'filename' => $fileName,
                'size' => $exists ? filesize($filePath) : 0,
                'last_modified' => $exists ? filemtime($filePath) : null,
                'url' => $url,
                'is_compressed' => $exists && pathinfo($filePath, PATHINFO_EXTENSION) === 'gz'
            ];
            
            if ($exists) {
                $sitemapInfo['formatted_size'] = $this->formatFileSize($sitemapInfo['size']);
                $sitemapInfo['formatted_date'] = date('Y-m-d H:i:s', $sitemapInfo['last_modified']);
            }
            
            $sitemaps[] = $sitemapInfo;
        }
        
        return [
            'success' => true,
            'sitemaps' => $sitemaps
        ];
    }

    /**
     * Check URL accessibility
     *
     * @param string $url
     * @return array
     */
    private function checkUrlAccessibility(string $url): array
    {
        try {
            $this->assertOwnStoreUrl($url);
            $startTime = microtime(true);

            $this->curl->setTimeout(10);
            $this->curl->setOption(CURLOPT_NOBODY, true); // HEAD request
            $this->curl->setOption(CURLOPT_FOLLOWLOCATION, false);
            $this->curl->get($url);

            $httpCode = $this->curl->getStatus();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'success' => true,
                'accessible' => $httpCode === 200,
                'http_status' => $httpCode,
                'response_time' => $responseTime
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'accessible' => false,
                'error' => $this->safeErrorMessage->get($e)
            ];
        }
    }

    /**
     * Format validation result for frontend display
     *
     * @param ValidationResult $result
     * @return array
     */
    private function formatValidationResult(ValidationResult $result): array
    {
        $errors = [];
        foreach ($result->getErrors() as $error) {
            $errors[] = [
                'level' => $error['level'] ?? 'error',
                'message' => $error['message'] ?? '',
                'line' => $error['line'] ?? null,
                'column' => $error['column'] ?? null,
                'code' => $error['code'] ?? null
            ];
        }
        
        $warnings = [];
        foreach ($result->getWarnings() as $warning) {
            $warnings[] = [
                'level' => 'warning',
                'message' => $warning['message'] ?? '',
                'recommendation' => $warning['recommendation'] ?? null
            ];
        }
        
        return [
            'is_valid' => $result->isValid(),
            'score' => $result->getScore(),
            'summary' => $result->getSummary(),
            'errors' => $errors,
            'warnings' => $warnings,
            'metadata' => $result->getMetadata(),
            'performance_metrics' => $result->getPerformanceMetrics()
        ];
    }

    /**
     * Generate recommendations based on validation result
     *
     * @param ValidationResult $result
     * @return array
     */
    private function generateRecommendations(ValidationResult $result): array
    {
        $recommendations = [];
        
        if (!$result->isValid()) {
            $recommendations[] = [
                'priority' => 'high',
                'type' => 'error',
                'title' => __('Fix Validation Errors'),
                'description' => __('Your sitemap contains validation errors that must be fixed for proper search engine indexing.'),
                'action' => __('Review and fix all listed errors')
            ];
        }
        
        if ($result->getScore() < 80) {
            $recommendations[] = [
                'priority' => 'medium',
                'type' => 'optimization',
                'title' => __('Improve SEO Score'),
                'description' => __('Your sitemap score is below optimal. Consider implementing the suggested improvements.'),
                'action' => __('Address warnings and follow SEO best practices')
            ];
        }
        
        $metadata = $result->getMetadata();
        if (isset($metadata['file_size']) && $metadata['file_size'] > 10 * 1024 * 1024) { // 10MB
            $recommendations[] = [
                'priority' => 'medium',
                'type' => 'performance',
                'title' => __('Large Sitemap File'),
                'description' => __('Your sitemap file is quite large. Consider splitting it into multiple files.'),
                'action' => __('Enable sitemap splitting in configuration')
            ];
        }
        
        return $recommendations;
    }

    /**
     * Format file size for display
     *
     * @param int $bytes
     * @return string
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = floor(log($bytes) / log(1024));
        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }
}
