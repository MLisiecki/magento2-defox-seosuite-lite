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
use Magento\Catalog\Model\Category as CatalogCategory;
use Magento\Framework\App\Request\Http;

/**
 * Class to handle canonical URLs for category pages
 */
class Category
{
    /**
     * @var Http
     */
    private Http $request;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var Exclusion
     */
    private Exclusion $exclusion;

    /**
     * @param Http $request
     * @param Config $config
     * @param Exclusion $exclusion
     */
    public function __construct(
        Http $request,
        Config $config,
        Exclusion $exclusion
    ) {
        $this->request = $request;
        $this->config = $config;
        $this->exclusion = $exclusion;
    }

    /**
     * Get canonical URL for category
     *
     * ponytail: computed from the loaded entity on every call, no cache (CR-16) — FPC caches the page.
     *
     * @param CatalogCategory $category
     * @return string|null
     */
    public function getCanonicalUrl(CatalogCategory $category): ?string
    {
        // Return null if the module or canonical URLs for categories are disabled
        if (!$this->config->isEnabled() || !$this->config->isCategoryCanonicalEnabled()) {
            return null;
        }

        if (!$category->getId() || $this->exclusion->isCategoryExcluded($category)) {
            return null;
        }

        return $this->getCategoryCanonicalUrl($category);
    }

    /**
     * Get canonical URL for category
     *
     * @param CatalogCategory $category
     * @return string|null
     */
    private function getCategoryCanonicalUrl(CatalogCategory $category): ?string
    {
        // Handle pagination if needed
        $currentPage = (int)$this->request->getParam('p');
        
        if ($currentPage > 1 && $this->config->shouldUseCanonicalForCategoryPagination()) {
            return $this->getCategoryPaginationCanonicalUrl($category, $currentPage);
        }
        
        // Filtered pages (when indexable, see RobotsHandler) and the default case: clean category URL
        return $category->getUrl();
    }

    /**
     * Get canonical URL for category pagination
     *
     * @param CatalogCategory $category
     * @param int $currentPage
     * @return string
     */
    private function getCategoryPaginationCanonicalUrl(CatalogCategory $category, int $currentPage): string
    {
        $paginationCanonicalType = $this->config->getCategoryPaginationCanonicalType();
        
        switch ($paginationCanonicalType) {
            case 'first_page':
                // Point to the first page
                return $category->getUrl();
            case 'self':
                // Point to current page
                return $category->getUrl() . '?p=' . $currentPage;
            default:
                // Default to first page
                return $category->getUrl();
        }
    }
}
