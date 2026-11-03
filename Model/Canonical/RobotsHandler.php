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
use Magento\Catalog\Model\Layer\Category\FilterableAttributeList;
use Magento\Framework\App\Request\Http;
use Magento\Framework\View\Page\Config as PageConfig;

/**
 * Single source of the "should this page be NOINDEX" decision.
 *
 * Used by the robots meta plugin and by the canonical observers (canonical is suppressed on NOINDEX pages),
 * so both always see the same value (CR-13, CR-14).
 */
class RobotsHandler
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var Http
     */
    private Http $request;

    /**
     * @var PageConfig
     */
    private PageConfig $pageConfig;

    /**
     * @var Exclusion
     */
    private Exclusion $exclusion;

    /**
     * @var FilterableAttributeList
     */
    private FilterableAttributeList $filterableAttributes;

    /**
     * @var string[]|null
     */
    private ?array $filterVars = null;

    /**
     * @param Config $config
     * @param Http $request
     * @param PageConfig $pageConfig
     * @param Exclusion $exclusion
     * @param FilterableAttributeList $filterableAttributes
     */
    public function __construct(
        Config $config,
        Http $request,
        PageConfig $pageConfig,
        Exclusion $exclusion,
        FilterableAttributeList $filterableAttributes
    ) {
        $this->config = $config;
        $this->request = $request;
        $this->pageConfig = $pageConfig;
        $this->exclusion = $exclusion;
        $this->filterableAttributes = $filterableAttributes;
    }

    /**
     * Check if the current page should be NOINDEX
     *
     * @return bool
     */
    public function shouldAddNoindex(): bool
    {
        if (!$this->config->isEnabled() || !$this->config->isCanonicalEnabled()) {
            return false;
        }

        $action = (string)$this->request->getFullActionName();
        $canonicalForFilters = $this->config->shouldUseCanonicalForCategoryFilters();

        return self::isNoindex(
            $action,
            $this->request->getParams(),
            $action === 'catalog_category_view' && !$canonicalForFilters ? $this->getFilterVars() : [],
            $this->exclusion->isPageExcluded(),
            $canonicalForFilters,
            $this->config->shouldUseCanonicalForCategoryPagination()
        );
    }

    /**
     * Pure decision: NOINDEX when the URL is excluded, or a category page is filtered/paginated and the
     * matching "use canonical" option is off. Only request vars of category-layer filters count as
     * filters; tracking (utm_*, gclid, fbclid), sort/limit/mode and any other params never do.
     *
     * @param string $fullActionName
     * @param array $params Request params
     * @param string[] $filterVars Request vars of the category layered-navigation filters
     * @param bool $excluded URL matches an exclude pattern
     * @param bool $canonicalForFilters "Use canonical for category filters"
     * @param bool $canonicalForPagination "Use canonical for category pagination"
     * @return bool
     */
    public static function isNoindex(
        string $fullActionName,
        array $params,
        array $filterVars,
        bool $excluded,
        bool $canonicalForFilters,
        bool $canonicalForPagination
    ): bool {
        if ($excluded) {
            return true;
        }
        if ($fullActionName !== 'catalog_category_view') {
            return false;
        }

        foreach ($filterVars as $var) {
            if (isset($params[$var]) && $params[$var] !== '' && !$canonicalForFilters) {
                return true;
            }
        }

        $page = $params['p'] ?? 1;

        return is_scalar($page) && (int)$page > 1 && !$canonicalForPagination;
    }

    /**
     * Apply robots meta tag; never weakens a value that already says NOINDEX (e.g. NOINDEX,NOFOLLOW)
     *
     * @return void
     */
    public function applyRobotsTag(): void
    {
        if (!$this->shouldAddNoindex()) {
            return;
        }

        $current = (string)$this->pageConfig->getRobots();
        if (stripos($current, 'noindex') !== false) {
            return;
        }

        $this->pageConfig->setRobots(stripos($current, 'nofollow') !== false ? 'NOINDEX,NOFOLLOW' : 'NOINDEX,FOLLOW');
    }

    /**
     * Request vars of the category layer's filters: the core category filter ("cat") plus every filterable
     * attribute (core Layer\Category\FilterableAttributeList, the same list the layer builds its filters from).
     *
     * Read from config, not from the rendered layer state, so pages whose theme or display mode renders no
     * layered navigation still count as filtered (VERIFY_U4 R4).
     *
     * @return string[]
     */
    private function getFilterVars(): array
    {
        if ($this->filterVars !== null) {
            return $this->filterVars;
        }
        $vars = ['cat'];
        foreach ($this->filterableAttributes->getList() as $attribute) {
            $vars[] = (string)$attribute->getAttributeCode();
        }

        return $this->filterVars = $vars;
    }
}
