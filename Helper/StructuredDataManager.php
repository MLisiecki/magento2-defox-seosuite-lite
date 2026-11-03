<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki 
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Helper;

use Defox\SEOSuite\Helper\Config;
use Defox\SEOSuite\Model\StructuredData\GeneratorPool;
use Defox\SEOSuite\Model\StructuredData\Renderer\JsonLd;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Structured Data Manager
 * 
 * Manages structured data generation and storage for current page context.
 * Works similarly to Magento's metadata management in PageConfig.
 */
class StructuredDataManager
{
    /**
     * Upper bound for the "p" param passed to the category product list
     */
    private const MAX_PAGE = 10000;

    /**
     * @var Config
     */
    private Config $configHelper;
    
    /**
     * @var GeneratorPool
     */
    private GeneratorPool $generatorPool;
    
    /**
     * @var JsonLd
     */
    private JsonLd $jsonLdRenderer;
    
    /**
     * Current product/category as resolved by the core view controllers (non-deprecated wrapper over the registry)
     *
     * @var CatalogHelper
     */
    private CatalogHelper $catalogHelper;
    
    /**
     * @var HttpRequest
     */
    private HttpRequest $request;
    
    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;
    
    /**
     * Shared page instance loaded by Magento\Cms\Helper\Page (active + store-filtered)
     *
     * @var Page
     */
    private Page $cmsPage;
    
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var CatalogConfig
     */
    private CatalogConfig $catalogConfig;
    
    /**
     * @var array|null
     */
    private ?array $structuredDataItems = null;
    
    /**
     * @var string|null
     */
    private ?string $renderedJsonLd = null;

    /**
     * Constructor
     *
     * @param Config $configHelper
     * @param GeneratorPool $generatorPool
     * @param JsonLd $jsonLdRenderer
     * @param CatalogHelper $catalogHelper
     * @param HttpRequest $request
     * @param StoreManagerInterface $storeManager
     * @param Page $cmsPage
     * @param LoggerInterface $logger
     * @param CatalogConfig $catalogConfig
     */
    public function __construct(
        Config $configHelper,
        GeneratorPool $generatorPool,
        JsonLd $jsonLdRenderer,
        CatalogHelper $catalogHelper,
        HttpRequest $request,
        StoreManagerInterface $storeManager,
        Page $cmsPage,
        LoggerInterface $logger,
        CatalogConfig $catalogConfig
    ) {
        $this->configHelper = $configHelper;
        $this->generatorPool = $generatorPool;
        $this->jsonLdRenderer = $jsonLdRenderer;
        $this->catalogHelper = $catalogHelper;
        $this->request = $request;
        $this->storeManager = $storeManager;
        $this->cmsPage = $cmsPage;
        $this->logger = $logger;
        $this->catalogConfig = $catalogConfig;
    }

    /**
     * Get rendered JSON-LD for current page
     *
     * @return string
     */
    public function getJsonLd(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        // Return cached result if already rendered
        if ($this->renderedJsonLd !== null) {
            return $this->renderedJsonLd;
        }

        try {
            $structuredDataItems = $this->getStructuredDataItems();
            
            if (!empty($structuredDataItems)) {
                $this->renderedJsonLd = $this->jsonLdRenderer->renderMultiple($structuredDataItems);
            } else {
                $this->renderedJsonLd = '';
            }
            
        } catch (\Throwable $e) {
            $this->logger->warning(
                'SEOSuite: Error generating JSON-LD: ' . $e->getMessage(),
                ['exception' => $e]
            );
            $this->renderedJsonLd = '';
        }

        return $this->renderedJsonLd;
    }
    
    /**
     * Get structured data items for current page
     *
     * @return array
     */
    public function getStructuredDataItems(): array
    {
        // Return cached result if already generated
        if ($this->structuredDataItems !== null) {
            return $this->structuredDataItems;
        }

        $this->structuredDataItems = [];
        if (!$this->isEnabled()) {
            return $this->structuredDataItems;
        }
        
        try {
            $context = $this->getContext();
            
            // Generate structured data based on page type
            $entity = $this->getCurrentEntity();
            
            if ($entity !== null) {
                // Generate data for current entity
                $entityData = $this->generatorPool->generate($entity, $context);
                $this->structuredDataItems = array_merge($this->structuredDataItems, $entityData);
            }
            
            // Homepage: one pool call for the store runs Organization and WebSite once each (CR-12)
            if ($this->isHomePage()) {
                $storeData = $this->generatorPool->generate($this->storeManager->getStore());
                $this->structuredDataItems = array_merge($this->structuredDataItems, $storeData);
            }
            
        } catch (\Throwable $e) {
            $this->logger->warning(
                'SEOSuite: Error generating structured data items: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
        
        return $this->structuredDataItems;
    }
    
    /**
     * Check if structured data is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->configHelper->isEnabled() && $this->configHelper->isStructuredDataEnabled();
    }
    
    /**
     * Page entity for JSON-LD and meta templates (the one entity detection, CR-23/CR-38)
     *
     * Only the core view actions, and only the entity core itself resolved there: current_product /
     * current_category via Catalog\Helper\Data (registered after core's status/visibility/store checks) or the shared CMS page loaded by
     * Magento\Cms\Helper\Page (trade-off 2). Other actions that register current_product (reviews, send-friend,
     * third-party) get nothing, so two URLs never share one product's meta.
     *
     * @return Product|Category|Page|null
     */
    public function getCurrentEntity()
    {
        $entity = match ($this->request->getFullActionName()) {
            'catalog_product_view' => $this->catalogHelper->getProduct(),
            'catalog_category_view' => $this->catalogHelper->getCategory(),
            'cms_page_view', 'cms_index_index' => $this->cmsPage,
            default => null,
        };

        return ($entity instanceof Product || $entity instanceof Category || $entity instanceof Page)
            && $entity->getId() ? $entity : null;
    }
    
    /**
     * Get context for structured data generation
     *
     * @return array
     */
    private function getContext(): array
    {
        $params = $this->request->getParams();
        $sortable = isset($params['product_list_order'])
            ? array_map('strval', array_keys($this->catalogConfig->getAttributeUsedForSortByArray()))
            : [];

        return self::normalizeContext($params, $sortable);
    }

    /**
     * Allowlisted, normalised listing context (bounded value space; raw request values never reach a collection)
     *
     * @param array $params Request params
     * @param string[] $sortableCodes Attribute codes allowed for sorting
     * @return array
     */
    public static function normalizeContext(array $params, array $sortableCodes): array
    {
        $context = [];

        $page = $params['p'] ?? 1;
        $page = is_scalar($page) ? (int)$page : 1;
        if ($page > 1) {
            $context['current_page'] = min($page, self::MAX_PAGE);
        }

        $order = $params['product_list_order'] ?? null;
        if (is_string($order) && in_array($order, $sortableCodes, true)) {
            $dir = $params['product_list_dir'] ?? 'asc';
            $context['sort_order'] = $order;
            $context['sort_direction'] = is_string($dir) && strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        }

        // ponytail: filter params are not used by any generator, so they are not part of the context
        return $context;
    }
    
    /**
     * Check if current page is homepage: the store root ("/", store code already stripped, query ignored)
     * whatever route web/default/front points to, or the CMS home action itself
     *
     * @return bool
     */
    private function isHomePage(): bool
    {
        return in_array((string)$this->request->getOriginalPathInfo(), ['', '/'], true)
            || $this->request->getFullActionName() === 'cms_index_index';
    }
}
