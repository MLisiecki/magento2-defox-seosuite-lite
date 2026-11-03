<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki 
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Model\StructuredData\Generator;

use Defox\SEOSuite\Helper\Config;
use Defox\SEOSuite\Model\Text\Cleaner;
use Defox\SEOSuite\Model\StructuredData\AbstractGenerator;
use Defox\SEOSuite\Model\StructuredData\OrganizationDataProvider;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Helper\Category as CategoryHelper;
use Magento\Catalog\Model\Category as CategoryModel;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Category structured data generator
 * 
 * Generates schema.org CollectionPage structured data for catalog categories.
 */
class Category extends AbstractGenerator
{
    /**
     * @var CategoryHelper
     */
    private CategoryHelper $categoryHelper;
    
    /**
     * @var ProductCollectionFactory
     */
    private ProductCollectionFactory $productCollectionFactory;
    
    /**
     * Constructor
     *
     * @param Config $configHelper
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param Cleaner $textCleaner
     * @param OrganizationDataProvider $organizationData
     * @param CategoryHelper $categoryHelper
     * @param ProductCollectionFactory $productCollectionFactory
     */
    public function __construct(
        Config $configHelper,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        Cleaner $textCleaner,
        OrganizationDataProvider $organizationData,
        CategoryHelper $categoryHelper,
        ProductCollectionFactory $productCollectionFactory
    ) {
        parent::__construct($configHelper, $storeManager, $logger, $scopeConfig, $textCleaner, $organizationData);
        $this->categoryHelper = $categoryHelper;
        $this->productCollectionFactory = $productCollectionFactory;
    }
    
    /**
     * Generate structured data for category
     *
     * @param mixed $entity
     * @param array $context
     * @return array
     */
    protected function doGenerate($entity, array $context): array
    {
        /** @var CategoryModel $category */
        $category = $entity;
        
        // Determine if this is root category and should point to homepage
        $categoryUrl = $this->getCategoryUrl($category);
        
        $data = [
            '@type' => 'CollectionPage',
            'name' => $category->getName(),
            'url' => $categoryUrl
        ];

        $description = $this->getCategoryDescription($category);
        if ($description !== '') {
            $data['description'] = $description;
        }
        
        // Add breadcrumb
        $breadcrumb = $this->getBreadcrumb($category);
        if (!empty($breadcrumb)) {
            $data['breadcrumb'] = $breadcrumb;
        }
        
        // Add image if available
        $image = $this->getCategoryImage($category);
        if ($image) {
            $data['image'] = $image;
        }
        
        // Add main entity (ItemList of products)
        if ($this->configHelper->isStructuredDataCategoryProductListEnabled()) {
            $productList = $this->getProductList($category, $context);
            if (!empty($productList)) {
                $data['mainEntity'] = $productList;
            }
        }
        
        // Add isPartOf if it's a subcategory
        if ($category->getParentId() > 1) {
            $parentCategory = $category->getParentCategory();
            if ($parentCategory && $parentCategory->getId() > 1) {
                $data['isPartOf'] = [
                    '@type' => 'CollectionPage',
                    'name' => $parentCategory->getName(),
                    'url' => $this->getCategoryUrl($parentCategory)
                ];
            }
        }
        
        // Add publisher (organization) with FULL data from SEO Suite config
        $organization = $this->organizationData->getNamedData();
        if ($organization !== null) {
            $data['publisher'] = $organization;
        }
        
        return $data;
    }
    
    /**
     * Check if generator can handle entity
     *
     * @param mixed $entity
     * @return bool
     */
    public function canHandle($entity): bool
    {
        return $entity instanceof CategoryInterface || $entity instanceof CategoryModel;
    }
    
    /**
     * Get schema type
     *
     * @return string
     */
    public function getSchemaType(): string
    {
        return 'CollectionPage';
    }
    
    /**
     * Check if generator is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->configHelper->isStructuredDataCategoryEnabled($storeId);
    }
    
    /**
     * Get proper category URL
     *
     * @param CategoryModel $category
     * @return string
     */
    private function getCategoryUrl(CategoryModel $category): string
    {
        // Check if this is root/main category - if so, point to homepage
        if ($this->isRootCategory($category)) {
            return $this->storeManager->getStore()->getBaseUrl();
        }
        
        // Get the category URL
        $categoryUrl = $category->getUrl();
        
        // Clean admin URLs if they appear
        if (strpos($categoryUrl, '/admin_') !== false) {
            // Construct proper frontend URL
            $urlKey = $category->getUrlKey();
            if ($urlKey) {
                $baseUrl = $this->storeManager->getStore()->getBaseUrl();
                $suffix = $this->scopeConfig->getValue(
                    'catalog/seo/category_url_suffix',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $this->storeManager->getStore()->getId()
                );
                $categoryUrl = $baseUrl . $urlKey . ($suffix ?: '');
            } else {
                // Fallback to homepage for root categories without URL key
                return $this->storeManager->getStore()->getBaseUrl();
            }
        }
        
        return $this->cleanUrl($categoryUrl);
    }
    
    /**
     * Check if category is root/main category
     *
     * @param CategoryModel $category
     * @return bool
     */
    private function isRootCategory(CategoryModel $category): bool
    {
        $rootCategoryId = (int)$this->storeManager->getStore()->getRootCategoryId();
        return (int)$category->getId() <= $rootCategoryId;
    }
    
    /**
     * Get category description
     *
     * @param CategoryModel $category
     * @return string
     */
    private function getCategoryDescription(CategoryModel $category): string
    {
        $description = $category->getDescription();
        if ($description) {
            // Use the new HTML cleaning method from AbstractGenerator
            $description = $this->cleanHtml($description, 500);
        }
        
        return $description ?: '';
    }
    
    /**
     * Get category image URL
     *
     * @param CategoryModel $category
     * @return string|null
     */
    private function getCategoryImage(CategoryModel $category): ?string
    {
        $image = $category->getImageUrl();
        if ($image) {
            return $image;
        }
        
        // Try to get image from data
        $imageData = $category->getImage();
        if ($imageData) {
            $mediaUrl = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
            return $mediaUrl . 'catalog/category/' . $imageData;
        }
        
        return null;
    }
    
    /**
     * Get breadcrumb structured data
     *
     * @param CategoryModel $category
     * @return array
     */
    private function getBreadcrumb(CategoryModel $category): array
    {
        $breadcrumb = [
            '@type' => 'BreadcrumbList',
            'itemListElement' => []
        ];
        
        // Add Home as first breadcrumb item
        $breadcrumb['itemListElement'][] = [
            '@type' => 'ListItem',
            'position' => 1,
            'name' => __('Home')->render(),
            'item' => $this->storeManager->getStore()->getBaseUrl()
        ];

        $path = $category->getPath();
        $pathIds = explode('/', $path);

        // Remove root category IDs (1 and store root)
        $rootCategoryId = (int)$this->storeManager->getStore()->getRootCategoryId();
        $pathIds = array_filter($pathIds, function ($id) use ($rootCategoryId) {
            return (int)$id > $rootCategoryId;
        });

        // Active path categories in one query (core resource model), keyed by ID (CR-29)
        $parents = $category->getParentCategories();

        $position = 2;
        foreach ($pathIds as $categoryId) {
            $item = (int)$categoryId === (int)$category->getId() ? $category : ($parents[$categoryId] ?? null);
            if ($item === null) {
                continue;
            }
            $breadcrumb['itemListElement'][] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $item->getName(),
                'item' => $this->getCategoryUrl($item)
            ];
        }
        
        return $breadcrumb;
    }
    
    /**
     * Get product list for category
     *
     * @param CategoryModel $category
     * @param array $context
     * @return array
     */
    private function getProductList(CategoryModel $category, array $context): array
    {
        $productList = [
            '@type' => 'ItemList',
            'numberOfItems' => 0,
            'itemListElement' => []
        ];
        
        try {
            // Create product collection
            $collection = $this->productCollectionFactory->create();
            $collection->addCategoryFilter($category);
            $collection->addAttributeToSelect(['name', 'price', 'image', 'url_key']);
            $collection->addAttributeToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);
            $collection->addAttributeToFilter('visibility', ['neq' => \Magento\Catalog\Model\Product\Visibility::VISIBILITY_NOT_VISIBLE]);
            
            // Apply sorting from context if available
            if (isset($context['sort_order'])) {
                $collection->setOrder($context['sort_order'], $context['sort_direction'] ?? 'ASC');
            } else {
                $collection->setOrder('position', 'ASC');
            }
            
            // Limit to reasonable number of products
            $limit = $this->configHelper->getStructuredDataCategoryProductLimit() ?: 20;
            $collection->setPageSize($limit);
            
            // Apply current page if paginated
            if (isset($context['current_page'])) {
                $collection->setCurPage((int)$context['current_page']);
            }
            
            $position = 1;
            foreach ($collection as $product) {
                $productData = [
                    '@type' => 'ListItem',
                    'position' => $position,
                    'item' => [
                        '@type' => 'Thing',
                        '@id' => $this->cleanUrl($product->getProductUrl()),
                        'name' => $product->getName(),
                        'url' => $this->cleanUrl($product->getProductUrl())
                    ]
                ];
                
                // Don't add offers here - this is just a list item, not a full product
                
                $productList['itemListElement'][] = $productData;
                $position++;
            }
            
            $productList['numberOfItems'] = count($productList['itemListElement']);
            
        } catch (\Exception $e) {
            $this->logger->error('Error getting category products: ' . $e->getMessage());
        }
        
        return $productList;
    }
}
