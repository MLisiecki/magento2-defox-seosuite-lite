<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Model\StructuredData\Generator;

use Defox\SEOSuite\Model\StructuredData\AbstractGenerator;
use Magento\Cms\Model\Page;
use Magento\Cms\Helper\Page as PageHelper;
use Magento\Store\Model\ScopeInterface;

/**
 * WebPage structured data generator
 * 
 * Generates schema.org WebPage structured data for CMS pages.
 */
class WebPage extends AbstractGenerator
{
    /**
     * Generate structured data for CMS page
     *
     * @param mixed $entity
     * @param array $context
     * @return array
     */
    protected function doGenerate($entity, array $context): array
    {
        /** @var Page $page */
        $page = $entity;
        
        $data = [
            '@type' => 'WebPage',
            'name' => $page->getTitle(),
            'url' => $this->getPageUrl($page)
        ];

        $description = $this->getPageDescription($page);
        if ($description !== '') {
            $data['description'] = $description;
        }
        
        // Add dates
        if ($page->getCreationTime()) {
            $data['datePublished'] = $this->formatDate($page->getCreationTime());
        }
        
        if ($page->getUpdateTime()) {
            $data['dateModified'] = $this->formatDate($page->getUpdateTime());
        }
        
        // Add breadcrumb if available
        if (isset($context['breadcrumb']) && is_array($context['breadcrumb'])) {
            $data['breadcrumb'] = $this->generateBreadcrumb($context['breadcrumb']);
        }
        
        // Organization (full data, one guard): "about" on an AboutPage, "publisher" on every page
        $organization = $this->organizationData->getNamedData();
        if ($this->isAboutPage($page)) {
            $data['@type'] = 'AboutPage';
            if ($organization !== null) {
                $data['about'] = $organization;
            }
        }
        if ($organization !== null) {
            $data['publisher'] = $organization;
        }
        
        return $data;
    }
    
    /**
     * Words that identify article/blog pages (Article generator handles those, WebPage skips them).
     * UTF-8 + caseless (/iu), anchored at a word start so "history" or "myblog" do not match; plurals still do.
     * ponytail: keyword heuristic, a CMS page attribute ("is article") is the upgrade path if it misfires.
     */
    private const ARTICLE_PATTERN = '/(?<![\p{L}\p{N}])(?:blog|article|news|post|story|aktualno[sś]ci|artyku[lł]|wpis|nowo[sś]ci)/iu';

    /**
     * "About us" page words (UTF-8, caseless, word start; "o nas"/"o naszej firmie" must end the word so "o naszej ofercie" does not match)
     */
    private const ABOUT_PATTERN = '/(?<![\p{L}\p{N}])(?:about|o[ -]nas(?:zej[ -]firmie)?(?![\p{L}\p{N}]))/iu';

    /**
     * Check if generator can handle entity
     * Excludes pages that the Article generator handles to prevent duplicates.
     *
     * @param mixed $entity
     * @return bool
     */
    public function canHandle($entity): bool
    {
        return $entity instanceof Page && !$this->isArticleLikePage($entity);
    }

    /**
     * Check if page looks like an article/blog post (the one detection used by WebPage and Article)
     *
     * @param Page $page
     * @return bool
     */
    protected function isArticleLikePage(Page $page): bool
    {
        if (preg_match(self::ARTICLE_PATTERN, (string)$page->getIdentifier())
            || preg_match(self::ARTICLE_PATTERN, (string)$page->getTitle())
        ) {
            return true;
        }

        $layout = (string)$page->getPageLayout();
        if (strpos($layout, 'article') !== false) {
            return true;
        }

        return false;
    }
    
    /**
     * Get schema type
     *
     * @return string
     */
    public function getSchemaType(): string
    {
        return 'WebPage';
    }
    
    /**
     * Check if generator is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->configHelper->isStructuredDataEnabled($storeId);
    }
    
    /**
     * Get page URL
     *
     * @param Page $page
     * @return string
     */
    private function getPageUrl(Page $page): string
    {
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        $identifier = $page->getIdentifier();
        
        // Check if this is the configured home page
        if ($this->isHomePage($page)) {
            return $baseUrl;
        }
        
        // Handle standard home page identifiers
        if ($identifier === 'home' || $identifier === 'cms_index_index') {
            return $baseUrl;
        }
        
        return $this->cleanUrl($baseUrl . $identifier);
    }
    
    /**
     * Check if this CMS page is configured as home page
     *
     * @param Page $page
     * @return bool
     */
    private function isHomePage(Page $page): bool
    {
        // Get configured home page from store configuration
        $homePageId = $this->scopeConfig->getValue(
            PageHelper::XML_PATH_HOME_PAGE,
            ScopeInterface::SCOPE_STORE
        );
        
        // Value is "identifier", "identifier|id" (duplicate identifiers) or a page ID, as Cms\Helper\Page::prepareResultPage reads it
        $homePageId = explode('|', (string)$homePageId)[0];

        return $homePageId !== ''
            && ($homePageId === (string)$page->getIdentifier() || $homePageId === (string)$page->getId());
    }
    
    /**
     * Get page description
     *
     * @param Page $page
     * @return string
     */
    private function getPageDescription(Page $page): string
    {
        // Try meta description first
        $description = $page->getMetaDescription();
        
        if (!$description) {
            // Extract from content using HTML cleaning method
            $description = $this->extractCleanText((string)$page->getContent(), 2); // First 2 sentences
            if (mb_strlen($description, 'UTF-8') > 160) {
                $description = $this->cleanHtml($description, 160);
            }
        } else {
            // Clean meta description too
            $description = $this->cleanTextField($description, 300);
        }
        
        return $description ?: '';
    }
    
    /**
     * Check if page is about page
     *
     * @param Page $page
     * @return bool
     */
    private function isAboutPage(Page $page): bool
    {
        return preg_match(self::ABOUT_PATTERN, (string)$page->getIdentifier()) === 1
            || preg_match(self::ABOUT_PATTERN, (string)$page->getTitle()) === 1;
    }
    
    /**
     * Generate breadcrumb structured data
     *
     * @param array $breadcrumb
     * @return array
     */
    private function generateBreadcrumb(array $breadcrumb): array
    {
        $breadcrumbList = [
            '@type' => 'BreadcrumbList',
            'itemListElement' => []
        ];
        
        $position = 1;
        foreach ($breadcrumb as $item) {
            if (isset($item['label']) && isset($item['link'])) {
                $breadcrumbList['itemListElement'][] = [
                    '@type' => 'ListItem',
                    'position' => $position,
                    'name' => $item['label'],
                    'item' => $item['link']
                ];
                $position++;
            }
        }
        
        return $breadcrumbList;
    }
}
