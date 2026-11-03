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
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DataObject;
use Magento\Store\Model\StoreManagerInterface;
use Defox\SEOSuite\Helper\Config;
use Defox\SEOSuite\Logger\Logger;

/**
 * Category sitemap data provider
 *
 * Provides category URLs for sitemap generation with support for:
 * - Categories under the store's root category only
 * - Active / anchor / depth filters
 * - Multi-language support (hreflang)
 */
class CategoryProvider extends AbstractEntityProvider
{
    /**
     * @var CollectionFactory
     */
    private CollectionFactory $categoryCollectionFactory;

    /**
     * Constructor
     *
     * @param Config $config
     * @param Logger $logger
     * @param StoreManagerInterface $storeManager
     * @param ResourceConnection $resourceConnection
     * @param CollectionFactory $categoryCollectionFactory
     */
    public function __construct(
        Config $config,
        Logger $logger,
        StoreManagerInterface $storeManager,
        ResourceConnection $resourceConnection,
        CollectionFactory $categoryCollectionFactory
    ) {
        parent::__construct($config, $logger, $storeManager, $resourceConnection);
        $this->categoryCollectionFactory = $categoryCollectionFactory;
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'category';
    }

    /**
     * @inheritDoc
     */
    protected function createCollection(int $storeId): AbstractDb
    {
        $rootId = (int)$this->storeManager->getStore($storeId)->getRootCategoryId();

        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId);
        // Descendants of this store's root only (the root itself is not a page)
        $collection->addFieldToFilter('path', ['like' => '1/' . $rootId . '/%']);

        if ($this->config->getValue('defox_seosuite/sitemap/category/exclude_inactive', $storeId)) {
            $collection->addIsActiveFilter();
        }

        if ($this->config->getValue('defox_seosuite/sitemap/category/exclude_anchor', $storeId)) {
            $collection->addAttributeToFilter('is_anchor', 0);
        }

        // Depth 1 = children of the store root (level 2)
        $maxDepth = (int)$this->config->getValue('defox_seosuite/sitemap/category/max_depth', $storeId);
        if ($maxDepth > 0) {
            $collection->addFieldToFilter('level', ['lteq' => $maxDepth + 1]);
        }

        $this->addUrlRewriteFilter($collection, $storeId);

        return $collection;
    }

    /**
     * @inheritDoc
     */
    protected function createItem(DataObject $entity, string $url, int $storeId): SitemapItem
    {
        $item = new SitemapItem($url);

        $updatedAt = $entity->getData('updated_at');
        if ($updatedAt) {
            $item->setLastmod(date('Y-m-d', strtotime((string)$updatedAt)));
        }

        // Set change frequency and priority based on category level
        $item->setChangefreq($this->getCategoryChangefreq($entity, $storeId));
        $item->setPriority($this->getCategoryPriority($entity, $storeId));

        return $item;
    }

    /**
     * Get change frequency for category
     *
     * @param DataObject $category
     * @param int $storeId
     * @return string
     */
    private function getCategoryChangefreq(DataObject $category, int $storeId): string
    {
        // Higher level categories change less frequently
        $level = (int)$category->getData('level');

        if ($level <= 2) {
            return 'monthly';
        } elseif ($level <= 3) {
            return 'weekly';
        }

        return $this->getDefaultChangefreq($storeId);
    }

    /**
     * Get priority for category
     *
     * @param DataObject $category
     * @param int $storeId
     * @return float
     */
    private function getCategoryPriority(DataObject $category, int $storeId): float
    {
        // Higher level categories have higher priority
        $level = (int)$category->getData('level');

        if ($level <= 2) {
            return 0.8;
        } elseif ($level <= 3) {
            return 0.6;
        }

        return $this->getDefaultPriority($storeId);
    }
}
