<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */
declare(strict_types=1);

namespace Defox\SEOSuite\Model\Sitemap\Provider;

use Defox\SEOSuite\Model\Sitemap\SitemapItem;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DataObject;
use Magento\Store\Model\StoreManagerInterface;
use Defox\SEOSuite\Helper\Config;
use Defox\SEOSuite\Logger\Logger;

/**
 * CMS page sitemap data provider
 *
 * Provides CMS page URLs for sitemap generation with support for:
 * - Active pages assigned to the store only
 * - Multi-language support (hreflang, matched by identifier)
 * - Page exclusion rules
 */
class CmsPageProvider extends AbstractEntityProvider
{
    /**
     * @inheritDoc
     */
    protected const ID_COLUMN = 'main_table.page_id';

    /**
     * @var CollectionFactory
     */
    private CollectionFactory $pageCollectionFactory;

    /**
     * Constructor
     *
     * @param Config $config
     * @param Logger $logger
     * @param StoreManagerInterface $storeManager
     * @param ResourceConnection $resourceConnection
     * @param CollectionFactory $pageCollectionFactory
     */
    public function __construct(
        Config $config,
        Logger $logger,
        StoreManagerInterface $storeManager,
        ResourceConnection $resourceConnection,
        CollectionFactory $pageCollectionFactory
    ) {
        parent::__construct($config, $logger, $storeManager, $resourceConnection);
        $this->pageCollectionFactory = $pageCollectionFactory;
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'cms_page';
    }

    /**
     * @inheritDoc
     */
    protected function createCollection(int $storeId): AbstractDb
    {
        $collection = $this->pageCollectionFactory->create();
        $collection->addStoreFilter($storeId);
        $collection->addFieldToFilter('is_active', 1);

        $excluded = $this->getExcludedIdentifiers($storeId);
        if ($excluded) {
            $collection->addFieldToFilter('identifier', ['nin' => $excluded]);
        }

        return $collection;
    }

    /**
     * Path = identifier; the store's home page maps to the store base URL
     *
     * @inheritDoc
     */
    protected function getPaths(int $storeId, array $entities): array
    {
        $home = $this->config->getCmsUtilityPageIdentifier('cms_home_page', $storeId);
        $paths = [];
        foreach ($entities as $id => $page) {
            $identifier = (string)$page->getData('identifier');
            $paths[$id] = $identifier === $home ? '' : $identifier;
        }

        return $paths;
    }

    /**
     * Alternate = active page with the same identifier assigned to the other store
     *
     * @inheritDoc
     */
    protected function getAlternatePaths(int $altStoreId, array $entities): array
    {
        $identifiers = [];
        foreach ($entities as $id => $page) {
            $identifiers[$id] = (string)$page->getData('identifier');
        }

        $collection = $this->getCollection($altStoreId);
        $collection->addFieldToFilter('identifier', ['in' => array_values(array_unique($identifiers))]);
        $altPages = $collection->getItems();
        $altPaths = array_combine(
            array_map(static fn (DataObject $page): string => (string)$page->getData('identifier'), $altPages),
            $this->getPaths($altStoreId, $altPages)
        );

        $result = [];
        foreach ($identifiers as $id => $identifier) {
            if (isset($altPaths[$identifier])) {
                $result[$id] = $altPaths[$identifier];
            }
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    protected function createItem(DataObject $entity, string $url, int $storeId): SitemapItem
    {
        $item = new SitemapItem($url);

        $updatedAt = $entity->getData('update_time');
        if ($updatedAt) {
            $item->setLastmod(date('Y-m-d', strtotime((string)$updatedAt)));
        }

        $identifier = (string)$entity->getData('identifier');
        $isHome = $identifier === $this->config->getCmsUtilityPageIdentifier('cms_home_page', $storeId);
        $item->setChangefreq($this->getPageChangefreq($identifier, $isHome, $storeId));
        $item->setPriority($this->getPagePriority($identifier, $isHome, $storeId));

        return $item;
    }

    /**
     * Excluded identifiers: "Excluded Page Identifiers" plus, with "Exclude System Pages", the store's
     * 404 (web/default/cms_no_route) and cookies-disabled (web/default/cms_no_cookies) pages.
     * The home page is not a system page here: it is listed as the store base URL.
     *
     * @param int $storeId
     * @return string[]
     */
    private function getExcludedIdentifiers(int $storeId): array
    {
        $excluded = array_map(
            'trim',
            explode(',', (string)$this->config->getValue('defox_seosuite/sitemap/cms_page/excluded_pages', $storeId))
        );

        if ($this->config->getValue('defox_seosuite/sitemap/cms_page/exclude_system_pages', $storeId)) {
            $excluded[] = $this->config->getCmsUtilityPageIdentifier('cms_no_route', $storeId);
            $excluded[] = $this->config->getCmsUtilityPageIdentifier('cms_no_cookies', $storeId);
        }

        return array_values(array_unique(array_filter($excluded, 'strlen')));
    }

    /**
     * Get change frequency for CMS page
     *
     * @param string $identifier
     * @param bool $isHome
     * @param int $storeId
     * @return string
     */
    private function getPageChangefreq(string $identifier, bool $isHome, int $storeId): string
    {
        // Home page changes more frequently
        if ($isHome) {
            return 'daily';
        }

        // Policy pages change less frequently
        $policyPages = ['privacy-policy', 'terms-conditions', 'shipping-policy', 'return-policy'];
        if (in_array($identifier, $policyPages, true)) {
            return 'yearly';
        }

        return $this->getDefaultChangefreq($storeId);
    }

    /**
     * Get priority for CMS page
     *
     * @param string $identifier
     * @param bool $isHome
     * @param int $storeId
     * @return float
     */
    private function getPagePriority(string $identifier, bool $isHome, int $storeId): float
    {
        // Home page has highest priority
        if ($isHome) {
            return 1.0;
        }

        // Important pages have higher priority
        $importantPages = ['about-us', 'contact', 'customer-service'];
        if (in_array($identifier, $importantPages, true)) {
            return 0.8;
        }

        return $this->getDefaultPriority($storeId);
    }
}
