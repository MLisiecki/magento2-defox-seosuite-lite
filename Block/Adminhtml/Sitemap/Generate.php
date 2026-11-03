<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */
declare(strict_types=1);

namespace Defox\SEOSuite\Block\Adminhtml\Sitemap;

use Defox\SEOSuite\Helper\Config;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;

/**
 * Sitemap generation form block
 * 
 * Provides interface for manual sitemap generation including:
 * - Store view selection
 * - Generation options
 * - Progress tracking interface
 * - AJAX form handling
 */
class Generate extends Template
{
    /**
     * @var string
     */
    protected $_template = 'Defox_SEOSuite::sitemap/generate.phtml';

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * Constructor
     *
     * @param Context $context
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $config,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    /**
     * Get form key (inherited from parent)
     *
     * @return string
     */
    public function getFormKey(): string
    {
        return parent::getFormKey();
    }

    /**
     * Get generation URL
     *
     * @return string
     */
    public function getGenerationUrl(): string
    {
        return $this->getUrl('defox_seosuite/sitemap/generate');
    }

    /**
     * Get available stores
     *
     * @return array
     */
    public function getAvailableStores(): array
    {
        $stores = [];
        
        foreach ($this->storeManager->getStores() as $store) {
            if ($store->getIsActive()) {
                $stores[] = [
                    'value' => $store->getId(),
                    'label' => sprintf('%s (%s)', $store->getName(), $store->getCode()),
                    'website' => $store->getWebsite()->getName(),
                    'enabled' => (bool)$this->config->getValue('defox_seosuite/sitemap/enabled', (int)$store->getId())
                ];
            }
        }
        
        return $stores;
    }

    /**
     * Get default generation options
     *
     * @return array
     */
    public function getDefaultOptions(): array
    {
        return [
            // ponytail: images/hreflang follow each store's config (XmlGenerator), no global override
            'validate_after_generation' => false
        ];
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
     * Check if a store's sitemap directory is writable (creates it when missing)
     *
     * @param int $storeId
     * @return bool
     * @throws LocalizedException invalid stored Sitemap Path
     */
    public function isSitemapDirectoryWritable(int $storeId): bool
    {
        $fullPath = $this->config->getFullSitemapPath($storeId);

        try {
            // Tworzenie katalogu jeśli nie istnieje
            if (!is_dir($fullPath)) {
                if (!mkdir($fullPath, 0755, true) && !is_dir($fullPath)) {
                    return false;
                }
            }

            return is_writable($fullPath);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get estimated generation time
     *
     * @return string
     */
    public function getEstimatedTime(): string
    {
        $storeCount = count($this->getAvailableStores());
        
        // Basic estimation: 30-60 seconds per store depending on catalog size
        $minTime = $storeCount * 30;
        $maxTime = $storeCount * 60;
        
        if ($minTime < 60) {
            return $minTime . '-' . $maxTime . ' seconds';
        } elseif ($minTime < 3600) {
            return ceil($minTime / 60) . '-' . ceil($maxTime / 60) . ' minutes';
        } else {
            return '> 1 hour';
        }
    }

    /**
     * Get last generation info
     *
     * @return array|null
     */
    public function getLastGenerationInfo(): ?array
    {
        // This would integrate with StatisticsManager to get last generation info
        // For now, return null - will implement in dashboard phase
        return null;
    }

    /**
     * Get configuration warnings
     *
     * @return array
     */
    public function getConfigurationWarnings(): array
    {
        $warnings = [];
        
        if (!$this->isSitemapEnabled()) {
            $warnings[] = __('Sitemap generation is globally disabled. Check Settings first.');
        }
        
        $enabledStores = array_filter($this->getAvailableStores(), function($store) {
            return $store['enabled'];
        });
        
        if (empty($enabledStores)) {
            $warnings[] = __('No stores have sitemap enabled. Check store-specific settings.');
        }
        
        foreach ($enabledStores as $store) {
            $storeId = (int)$store['value'];
            try {
                if (!$this->isSitemapDirectoryWritable($storeId)) {
                    $warnings[] = __(
                        'Sitemap directory "%1" is not writable.',
                        $this->config->getSitemapPath($storeId)
                    );
                }
            } catch (LocalizedException $e) {
                $warnings[] = __('Store %1: %2', $store['label'], $e->getMessage());
            }
        }
        
        return $warnings;
    }

    /**
     * Get robots.txt setup information: one "Sitemap:" line per store with sitemap enabled
     *
     * @return array
     */
    public function getRobotsTxtInfo(): array
    {
        $sitemapUrls = [];
        foreach ($this->getAvailableStores() as $store) {
            if (!$store['enabled']) {
                continue;
            }
            $storeId = (int)$store['value'];
            try {
                $fileName = $this->config->getPublishedSitemapFileName($storeId)
                    ?? $this->config->getSitemapFileName(
                        $storeId,
                        '',
                        (bool)$this->config->getValue('defox_seosuite/sitemap/enable_compression', $storeId)
                    );
                $sitemapUrls[$store['label']] = $this->config->getSitemapUrl($storeId, $fileName);
            } catch (LocalizedException $e) {
                continue; // invalid path is already reported by getConfigurationWarnings()
            }
        }

        return [
            'robots_file_path' => 'pub/robots.txt',
            'sitemap_urls' => array_values($sitemapUrls),
            'robots_content_example' => $this->generateRobotsExample($sitemapUrls)
        ];
    }

    /**
     * Generate example robots.txt content
     *
     * @param string[] $sitemapUrls store label => sitemap URL
     * @return string
     */
    private function generateRobotsExample(array $sitemapUrls): string
    {
        $content = "User-agent: *\n";
        $content .= "Allow: /\n\n";
        $content .= "# Add these lines to inform search engines about your sitemaps\n";
        $content .= "# (robots.txt is per domain: keep only the lines of stores served from that domain)\n";
        foreach ($sitemapUrls as $label => $url) {
            $content .= '# ' . $label . "\n" . 'Sitemap: ' . $url . "\n";
        }

        return rtrim($content);
    }
}
