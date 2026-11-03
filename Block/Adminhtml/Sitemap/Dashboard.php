<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki 
 * @copyright   Copyright (c) 2025 deFox
 * @license     GPL v3
 */
declare(strict_types=1);

namespace Defox\SEOSuite\Block\Adminhtml\Sitemap;

use Defox\SEOSuite\Helper\Config;
use Defox\SEOSuite\Model\Sitemap\Analytics\StatisticsManager;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Sitemap dashboard block
 * 
 * Provides data and methods for sitemap analytics dashboard including:
 * - KPI metrics preparation
 * - Chart data configuration
 * - Store information
 * - AJAX endpoints URLs
 */
class Dashboard extends Template
{
    /**
     * @var string
     */
    protected $_template = 'Defox_SEOSuite::sitemap/dashboard.phtml';

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var StatisticsManager
     */
    private StatisticsManager $statisticsManager;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * Constructor
     *
     * @param Context $context
     * @param Config $config
     * @param StatisticsManager $statisticsManager
     * @param StoreManagerInterface $storeManager
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $config,
        StatisticsManager $statisticsManager,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->statisticsManager = $statisticsManager;
        $this->storeManager = $storeManager;
    }

    /**
     * Get AJAX URLs for dashboard data loading
     *
     * @return array
     */
    public function getAjaxUrls(): array
    {
        return [
            'kpi_data' => $this->getUrl('defox_seosuite/sitemap/dashboard', ['ajax_action' => 'kpi_data']),
            'chart_data' => $this->getUrl('defox_seosuite/sitemap/dashboard', ['ajax_action' => 'chart_data']),
            'recent_history' => $this->getUrl('defox_seosuite/sitemap/dashboard', ['ajax_action' => 'recent_history']),
            'performance_stats' => $this->getUrl('defox_seosuite/sitemap/dashboard', ['ajax_action' => 'performance_stats']),
            'provider_comparison' => $this->getUrl('defox_seosuite/sitemap/dashboard', ['ajax_action' => 'provider_comparison']),
            'store_performance' => $this->getUrl('defox_seosuite/sitemap/dashboard', ['ajax_action' => 'store_performance']),
            'export_data' => $this->getUrl('defox_seosuite/sitemap/dashboard', ['ajax_action' => 'export_data']),
            'benchmark_data' => $this->getUrl('defox_seosuite/sitemap/dashboard', ['ajax_action' => 'benchmark_data']),
            'filtered_stats' => $this->getUrl('defox_seosuite/sitemap/dashboard', ['ajax_action' => 'filtered_stats'])
        ];
    }

    /**
     * Get generate sitemap URL
     *
     * @return string
     */
    public function getGenerateSitemapUrl(): string
    {
        return $this->getUrl('defox_seosuite/sitemap/generate');
    }

    /**
     * Get validate sitemap URL
     *
     * @return string
     */
    public function getValidateSitemapUrl(): string
    {
        return $this->getUrl('defox_seosuite/sitemap/validate');
    }

    /**
     * Get settings URL
     *
     * @return string
     */
    public function getSettingsUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit/section/defox_seosuite');
    }

    /**
     * Check if sitemap is globally enabled
     *
     * @return bool
     */
    public function isSitemapEnabled(): bool
    {
        return $this->config->isSitemapEnabled();
    }

    /**
     * Get stores with sitemap enabled
     *
     * @return array
     */
    public function getStoresWithSitemapEnabled(): array
    {
        $stores = [];
        
        foreach ($this->storeManager->getStores() as $store) {
            if ($store->getIsActive()) {
                $enabled = (bool)$this->config->getValue('defox_seosuite/sitemap/enabled', (int)$store->getId());
                if ($enabled) {
                    $stores[] = [
                        'id' => $store->getId(),
                        'name' => $store->getName(),
                        'code' => $store->getCode(),
                        'website' => $store->getWebsite()->getName()
                    ];
                }
            }
        }
        
        return $stores;
    }

    /**
     * Get dashboard refresh interval in seconds
     *
     * @return int
     */
    public function getDashboardRefreshInterval(): int
    {
        return 300;
    }

    /**
     * Get maximum dashboard records to display
     *
     * @return int
     */
    public function getMaxDashboardRecords(): int
    {
        return 50;
    }

    /**
     * Get Chart.js configuration
     *
     * @return array
     */
    public function getChartConfig(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'position' => 'bottom'
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false
                ]
            ],
            'scales' => [
                'x' => [
                    'display' => true,
                    'title' => [
                        'display' => true,
                        'text' => 'Date'
                    ]
                ],
                'y' => [
                    'display' => true,
                    'title' => [
                        'display' => true,
                        'text' => 'Count'
                    ]
                ]
            ]
        ];
    }

    /**
     * Get color scheme for charts
     *
     * @return array
     */
    public function getChartColors(): array
    {
        return [
            'primary' => '#1979c3',
            'success' => '#5cb85c',
            'warning' => '#f0ad4e',
            'danger' => '#d9534f',
            'info' => '#5bc0de',
            'secondary' => '#6c757d',
            'gradient_primary' => 'linear-gradient(90deg, #1979c3, #006bb4)',
            'gradient_success' => 'linear-gradient(90deg, #5cb85c, #449d44)',
            'gradient_warning' => 'linear-gradient(90deg, #f0ad4e, #ec971f)'
        ];
    }

    /**
     * Get initial KPI data (server-side rendered)
     *
     * @return array
     */
    public function getInitialKpiData(): array
    {
        try {
            // Get basic statistics without heavy processing
            $stores = $this->storeManager->getStores();
            $enabledStores = count($this->getStoresWithSitemapEnabled());
            
            // Get last generation info if available
            $lastGeneration = $this->statisticsManager->getLastGenerationByStore();
            
            return [
                'total_stores' => count($stores),
                'enabled_stores' => $enabledStores,
                'last_generation_time' => $lastGeneration['generation_time'] ?? null,
                'last_generation_status' => isset($lastGeneration['success']) 
                    ? ($lastGeneration['success'] ? 'success' : 'error') 
                    : null,
                'sitemap_enabled' => $this->isSitemapEnabled()
            ];
            
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get dashboard warnings
     *
     * @return array
     */
    public function getDashboardWarnings(): array
    {
        $warnings = [];
        
        if (!$this->isSitemapEnabled()) {
            $warnings[] = [
                'type' => 'error',
                'message' => __('Sitemap generation is globally disabled.'),
                'action_url' => $this->getSettingsUrl(),
                'action_text' => __('Enable in Settings')
            ];
        }
        
        $enabledStores = $this->getStoresWithSitemapEnabled();
        if (empty($enabledStores)) {
            $warnings[] = [
                'type' => 'warning',
                'message' => __('No stores have sitemap enabled.'),
                'action_url' => $this->getSettingsUrl(),
                'action_text' => __('Configure Stores')
            ];
        }
        
        // Check if statistics table exists
        try {
            $this->statisticsManager->getLastGenerationByStore();
        } catch (\Exception $e) {
            $warnings[] = [
                'type' => 'info',
                'message' => __('No sitemap generation history available. Generate your first sitemap to see statistics.'),
                'action_url' => $this->getGenerateSitemapUrl(),
                'action_text' => __('Generate Now')
            ];
        }
        
        return $warnings;
    }

    /**
     * Check if dashboard should show welcome message
     *
     * @return bool
     */
    public function shouldShowWelcomeMessage(): bool
    {
        try {
            $lastGeneration = $this->statisticsManager->getLastGenerationByStore();
            return empty($lastGeneration);
        } catch (\Exception $e) {
            return true;
        }
    }
}
