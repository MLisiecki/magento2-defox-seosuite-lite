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
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Helper\Stock as StockHelper;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DataObject;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Defox\SEOSuite\Helper\Config;
use Defox\SEOSuite\Logger\Logger;

/**
 * Product sitemap data provider
 *
 * Provides product URLs for sitemap generation with support for:
 * - Multiple product types
 * - Stock status filtering
 * - Visibility filtering
 * - Multi-language support (hreflang)
 * - Product images
 */
class ProductProvider extends AbstractEntityProvider
{
    /**
     * @var CollectionFactory
     */
    private CollectionFactory $productCollectionFactory;

    /**
     * @var StockHelper
     */
    private StockHelper $stockHelper;

    /**
     * Constructor
     *
     * @param Config $config
     * @param Logger $logger
     * @param StoreManagerInterface $storeManager
     * @param ResourceConnection $resourceConnection
     * @param CollectionFactory $productCollectionFactory
     * @param StockHelper $stockHelper
     */
    public function __construct(
        Config $config,
        Logger $logger,
        StoreManagerInterface $storeManager,
        ResourceConnection $resourceConnection,
        CollectionFactory $productCollectionFactory,
        StockHelper $stockHelper
    ) {
        parent::__construct($config, $logger, $storeManager, $resourceConnection);
        $this->productCollectionFactory = $productCollectionFactory;
        $this->stockHelper = $stockHelper;
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'product';
    }

    /**
     * @inheritDoc
     */
    public function supportsImages(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    protected function createCollection(int $storeId): AbstractDb
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addStoreFilter($storeId);
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);

        if ($this->config->getValue('defox_seosuite/sitemap/product/exclude_invisible', $storeId)) {
            $collection->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE]);
        }

        if ($this->config->getValue('defox_seosuite/sitemap/product/exclude_out_of_stock', $storeId)) {
            $this->stockHelper->addInStockFilterToCollection($collection);
        }

        $excludedTypes = $this->config->getValue('defox_seosuite/sitemap/product/excluded_types', $storeId);
        if ($excludedTypes) {
            $collection->addAttributeToFilter('type_id', ['nin' => explode(',', $excludedTypes)]);
        }

        if ($this->config->getValue('defox_seosuite/sitemap/product/include_images', $storeId)) {
            $collection->addAttributeToSelect(['name', 'image', 'small_image']);
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

        $item->setChangefreq($this->getDefaultChangefreq($storeId));
        $item->setPriority($this->getDefaultPriority($storeId));

        if ($this->config->getValue('defox_seosuite/sitemap/product/include_images', $storeId)) {
            $item->setImages($this->getProductImages($entity, $storeId));
        }

        return $item;
    }

    /**
     * Get product images (attributes loaded with the batch)
     *
     * @param DataObject $product
     * @param int $storeId
     * @return array
     */
    private function getProductImages(DataObject $product, int $storeId): array
    {
        $images = [];
        $mediaUrl = $this->getBaseUrl($storeId, UrlInterface::URL_TYPE_MEDIA);

        foreach (array_unique(array_filter([$product->getData('image'), $product->getData('small_image')])) as $file) {
            if ($file !== 'no_selection') {
                $images[] = [
                    'url' => $mediaUrl . 'catalog/product/' . ltrim((string)$file, '/'),
                    'title' => $product->getData('name'),
                    'caption' => null
                ];
            }
        }

        return $images;
    }
}
