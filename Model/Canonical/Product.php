<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki 
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Model\Canonical;

use Defox\SEOSuite\Helper\Config;
use Magento\Catalog\Model\Product as CatalogProduct;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class to handle canonical URLs for product pages
 */
class Product
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var Exclusion
     */
    private Exclusion $exclusion;

    /**
     * @var CategoryCollectionFactory
     */
    private CategoryCollectionFactory $categoryCollectionFactory;

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param Exclusion $exclusion
     * @param CategoryCollectionFactory $categoryCollectionFactory
     */
    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        Exclusion $exclusion,
        CategoryCollectionFactory $categoryCollectionFactory
    ) {
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->exclusion = $exclusion;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
    }

    /**
     * Get canonical URL for product
     *
     * ponytail: computed from the loaded entity on every call, no cache (CR-16) — FPC caches the page.
     *
     * @param CatalogProduct $product
     * @return string|null
     */
    public function getCanonicalUrl(CatalogProduct $product): ?string
    {
        // Return null if the module or canonical URLs for products are disabled
        if (!$this->config->isEnabled() || !$this->config->isProductCanonicalEnabled()) {
            return null;
        }

        if (!$product->getId() || $this->exclusion->isProductExcluded($product)) {
            return null;
        }

        return $this->getProductCanonicalUrl($product);
    }

    /**
     * Get canonical URL for product according to configuration
     *
     * @param CatalogProduct $product
     * @return string|null
     */
    private function getProductCanonicalUrl(CatalogProduct $product): ?string
    {
        $canonicalUrlType = $this->config->getProductCanonicalUrlType();
        
        switch ($canonicalUrlType) {
            case 'with_category':
            case 'shortest_category':
            case 'longest_category':
                return $this->getProductUrlWithCategory($product, $canonicalUrlType)
                    ?? $this->getProductUrlWithoutParams($product);
            case 'product_url':
            default:
                return $this->getProductUrlWithoutParams($product);
        }
    }

    /**
     * Get product URL without any parameters
     *
     * @param CatalogProduct $product
     * @return string
     */
    private function getProductUrlWithoutParams(CatalogProduct $product): string
    {
        return $product->getUrlModel()->getUrl($product, ['_ignore_category' => true]);
    }

    /**
     * Product URL inside a category path. The category is chosen from one collection query:
     * "with_category" = first assigned active category in the store tree, "shortest_category"/
     * "longest_category" = lowest/highest level. The URL comes from core Product\Url::getUrl(), which only
     * honours a category through the product's category_id with no "_ignore_category" param and looks up
     * the url_rewrite by metadata.category_id (needs "Use Categories Path for Product URLs" = Yes).
     * Null when core finds no rewrite for that path.
     *
     * @param CatalogProduct $product
     * @param string $mode
     * @return string|null
     */
    private function getProductUrlWithCategory(CatalogProduct $product, string $mode): ?string
    {
        $categoryIds = array_map('intval', (array)$product->getCategoryIds());
        if (empty($categoryIds)) {
            return null;
        }

        $store = $this->storeManager->getStore();
        $levels = [];
        $collection = $this->categoryCollectionFactory->create()
            ->setStoreId($store->getId())
            ->addIdFilter($categoryIds)
            ->addIsActiveFilter()
            ->addPathsFilter('1/' . (int)$store->getRootCategoryId() . '/');
        foreach ($collection as $category) {
            $levels[(int)$category->getId()] = (int)$category->getLevel();
        }
        if (empty($levels)) {
            return null;
        }

        if ($mode === 'shortest_category') {
            asort($levels);
            $categoryId = array_key_first($levels);
        } elseif ($mode === 'longest_category') {
            arsort($levels);
            $categoryId = array_key_first($levels);
        } else {
            $categoryId = current(array_intersect($categoryIds, array_keys($levels)));
        }

        // Clone: core caches the resolved request_path on the product it is given
        $clone = clone $product;
        $clone->unsetData('url_data_object');
        $clone->setRequestPath(null)->setCategoryId($categoryId)->setDoNotUseCategoryId(false);
        $url = $product->getUrlModel()->getUrl($clone, []);

        return $clone->getRequestPath() ? $url : null;
    }
}
