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
use Defox\SEOSuite\Model\Sitemap\Analytics\StatisticsManager;
use Defox\SEOSuite\Model\Sitemap\SitemapGeneratorInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sitemap generation controller
 * 
 * Handles manual sitemap generation through admin panel with:
 * - Store view selection
 * - Generation options configuration
 * - Real-time progress tracking
 * - AJAX form submission
 */
class Generate extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    /**
     * Authorization level of a basic admin session
     */
    public const ADMIN_RESOURCE = 'Defox_SEOSuite::sitemap_generate';

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
     * @var SitemapGeneratorInterface
     */
    private SitemapGeneratorInterface $sitemapGenerator;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var StatisticsManager
     */
    private StatisticsManager $statisticsManager;

    /**
     * @var SafeErrorMessage
     */
    private SafeErrorMessage $safeErrorMessage;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param JsonFactory $resultJsonFactory
     * @param Config $config
     * @param SitemapGeneratorInterface $sitemapGenerator
     * @param StoreManagerInterface $storeManager
     * @param StatisticsManager $statisticsManager
     * @param SafeErrorMessage $safeErrorMessage
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        JsonFactory $resultJsonFactory,
        Config $config,
        SitemapGeneratorInterface $sitemapGenerator,
        StoreManagerInterface $storeManager,
        StatisticsManager $statisticsManager,
        SafeErrorMessage $safeErrorMessage,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->config = $config;
        $this->sitemapGenerator = $sitemapGenerator;
        $this->storeManager = $storeManager;
        $this->statisticsManager = $statisticsManager;
        $this->safeErrorMessage = $safeErrorMessage;
        $this->logger = $logger;
    }

    /**
     * Execute action
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        if ($this->getRequest()->isPost()) {
            return $this->processGeneration();
        }

        return $this->showGenerationForm();
    }

    /**
     * Show generation form
     *
     * @return ResultInterface
     */
    private function showGenerationForm(): ResultInterface
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Defox_SEOSuite::sitemap_generate');
        $resultPage->getConfig()->getTitle()->prepend(__('Sitemap Management - Generate'));
        
        return $resultPage;
    }

    /**
     * Process sitemap generation via AJAX
     *
     * @return ResultInterface
     */
    private function processGeneration(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();
        
        try {
            $storeIds = $this->getRequest()->getParam('store_ids', []);
            $options = $this->getGenerationOptions();
            
            if (empty($storeIds)) {
                // Generate for all active stores if none selected
                $stores = $this->storeManager->getStores();
                $storeIds = [];
                foreach ($stores as $store) {
                    if ($store->getIsActive()) {
                        $storeIds[] = (int)$store->getId();
                    }
                }
            }
            
            $results = [];
            $totalGenerated = 0;
            $errors = [];
            
            foreach ($storeIds as $storeId) {
                try {
                    $store = $this->storeManager->getStore($storeId);
                    
                    if (!$store->getIsActive()) {
                        continue;
                    }
                    
                    $startTime = microtime(true);
                    $memoryBefore = memory_get_usage();
                    $files = $this->sitemapGenerator->generate((int)$storeId, $options);
                    $duration = microtime(true) - $startTime;
                    $memoryAfter = memory_get_usage();
                    
                    $totalUrls = (int)($this->sitemapGenerator->getGenerationStats()['total_urls'] ?? 0);
                    
                    // Record statistics
                    $this->recordStatistics([
                        'store_id' => $storeId,
                        'duration' => $duration,
                        'total_urls' => $totalUrls,
                        'files' => $files,
                        'errors' => [],
                        'memory_usage' => $memoryAfter - $memoryBefore,
                        'memory_peak' => memory_get_peak_usage(),
                        'success' => true
                    ]);
                    
                    $results[] = [
                        'store_id' => $storeId,
                        'store_name' => $store->getName(),
                        'success' => true,
                        'files_count' => count($files),
                        'files' => array_map('basename', $files),
                        'duration' => round($duration, 2),
                        'message' => __('Successfully generated %1 sitemap files', count($files))
                    ];
                    
                    $totalGenerated += count($files);
                    
                } catch (\Exception $e) {
                    $store = $this->storeManager->getStore($storeId);
                    $safeMessage = $this->safeErrorMessage->get($e);
                    $errorMessage = sprintf(
                        'Store %s (%d): %s',
                        $store->getName(),
                        $storeId,
                        $safeMessage
                    );
                    $errors[] = $errorMessage;
                    
                    // Record failed generation statistics
                    $this->recordStatistics([
                        'store_id' => $storeId,
                        'duration' => 0,
                        'total_urls' => 0,
                        'files' => [],
                        'errors' => [$safeMessage],
                        'success' => false
                    ]);
                    
                    $results[] = [
                        'store_id' => $storeId,
                        'store_name' => $store->getName(),
                        'success' => false,
                        'error' => $safeMessage,
                        'message' => __('Error generating sitemap: %1', $safeMessage)
                    ];
                }
            }
            
            $success = empty($errors);
            $message = $success 
                ? __('Successfully generated %1 sitemap files for %2 stores', $totalGenerated, count($storeIds))
                : __('Generation completed with %1 errors', count($errors));
            
            return $result->setData([
                'success' => $success,
                'message' => $message,
                'results' => $results,
                'summary' => [
                    'stores_processed' => count($storeIds),
                    'files_generated' => $totalGenerated,
                    'errors_count' => count($errors),
                    'success_rate' => count($storeIds) > 0 ? round((count($storeIds) - count($errors)) / count($storeIds) * 100, 1) : 0
                ]
            ]);
            
        } catch (\Exception $e) {
            $safeMessage = $this->safeErrorMessage->get($e);
            return $result->setData([
                'success' => false,
                'message' => __('Fatal error during generation: %1', $safeMessage),
                'error' => $safeMessage
            ]);
        }
    }

    /**
     * Get generation options from request
     *
     * @return array
     */
    private function getGenerationOptions(): array
    {
        return [
            'validate_after_generation' => (bool)$this->getRequest()->getParam('validate_after_generation', false)
        ];
    }

    /**
     * Record generation statistics
     *
     * @param array $stats
     * @return void
     */
    private function recordStatistics(array $stats): void
    {
        try {
            $this->statisticsManager->recordGenerationStats($stats);
        } catch (\Exception $e) {
            // Don't fail generation if statistics recording fails
            $this->logger->warning('Defox_SEOSuite: sitemap statistics not recorded: ' . $e->getMessage());
        }
    }
}
