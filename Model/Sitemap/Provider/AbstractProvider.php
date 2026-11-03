<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */
declare(strict_types=1);

namespace Defox\SEOSuite\Model\Sitemap\Provider;

use Defox\SEOSuite\Helper\Config;
use Defox\SEOSuite\Logger\Logger;
use Defox\SEOSuite\Model\Sitemap\SitemapProviderInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Abstract base class for sitemap providers
 *
 * Provides common functionality for all sitemap data providers
 */
abstract class AbstractProvider implements SitemapProviderInterface
{
    /**
     * Valid <changefreq> values (sitemaps.org)
     */
    protected const CHANGEFREQS = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

    /**
     * @var Config
     */
    protected Config $config;

    /**
     * @var Logger
     */
    protected Logger $logger;

    /**
     * @var StoreManagerInterface
     */
    protected StoreManagerInterface $storeManager;

    /**
     * @var ResourceConnection
     */
    protected ResourceConnection $resourceConnection;

    /**
     * Constructor
     *
     * @param Config $config
     * @param Logger $logger
     * @param StoreManagerInterface $storeManager
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        Config $config,
        Logger $logger,
        StoreManagerInterface $storeManager,
        ResourceConnection $resourceConnection
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(int $storeId): bool
    {
        // Check if the main SEO Suite is enabled
        if (!$this->config->isEnabled($storeId)) {
            return false;
        }

        // Check if sitemap functionality is enabled
        if (!$this->config->getValue('defox_seosuite/sitemap/enabled', $storeId)) {
            return false;
        }

        // Check if this specific provider is enabled
        $configPath = 'sitemap/' . $this->getCode() . '/enabled';
        $providerEnabled = $this->config->getValue($configPath, $storeId);

        $this->logger->debug(sprintf(
            'Provider %s: Checking config path "defox_seosuite/%s" for store %d, value: %s',
            $this->getCode(),
            $configPath,
            $storeId,
            var_export($providerEnabled, true)
        ));

        // Fallback to true if configuration doesn't exist (for backwards compatibility)
        if ($providerEnabled === null) {
            $this->logger->warning(sprintf(
                'Provider configuration "sitemap/%s/enabled" not found for store %d, defaulting to enabled',
                $this->getCode(),
                $storeId
            ));
            return true;
        }

        $isEnabled = (bool)$providerEnabled;
        return $isEnabled;
    }

    /**
     * @inheritDoc
     *
     * @param int|null $storeId Store scope of the setting (default scope when null)
     */
    public function getDefaultChangefreq(?int $storeId = null): string
    {
        $changefreq = $this->config->getValue('defox_seosuite/sitemap/' . $this->getCode() . '/changefreq', $storeId);
        return in_array($changefreq, self::CHANGEFREQS, true) ? $changefreq : 'daily';
    }

    /**
     * @inheritDoc
     *
     * @param int|null $storeId Store scope of the setting (default scope when null)
     */
    public function getDefaultPriority(?int $storeId = null): float
    {
        $priority = $this->config->getValue('defox_seosuite/sitemap/' . $this->getCode() . '/priority', $storeId);
        if ($priority !== null && $priority !== '') {
            $priorityValue = (float)$priority;
            // Ensure priority is within valid range (0.0 to 1.0)
            return max(0.0, min(1.0, $priorityValue));
        }

        // Default priorities based on provider type
        $defaults = [
            'product' => 0.8,
            'category' => 0.7,
            'cms_page' => 0.6,
            'additional_links' => 0.5
        ];

        return $defaults[$this->getCode()] ?? 0.5;
    }

    /**
     * @inheritDoc
     */
    public function supportsHreflang(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function supportsImages(): bool
    {
        return false;
    }

    /**
     * Store frontend base URL ("https://shop.test/" or "https://shop.test/en/"), as core Magento_Sitemap builds it
     *
     * Read from the store's web/* config, so it is the public URL whatever area (adminhtml, crontab) runs generation.
     *
     * @param int $storeId
     * @param string $type UrlInterface::URL_TYPE_*
     * @return string
     */
    protected function getBaseUrl(int $storeId, string $type = UrlInterface::URL_TYPE_LINK): string
    {
        $store = $this->storeManager->getStore($storeId);
        return rtrim((string)$store->getBaseUrl($type, $store->isUrlSecure()), '/') . '/';
    }
}
