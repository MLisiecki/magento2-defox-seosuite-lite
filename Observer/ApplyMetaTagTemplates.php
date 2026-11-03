<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki 
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Observer;

use Defox\SEOSuite\Helper\Config;
use Defox\SEOSuite\Helper\StructuredDataManager;
use Defox\SEOSuite\Model\MetaTag\Manager;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Category;
use Magento\Cms\Model\Page;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\View\Page\Config as PageConfig;

/**
 * Enhanced observer for applying meta tag templates
 *
 * Listens to 'layout_render_before' (frontend) and applies meta tag templates to the page entity resolved by
 * StructuredDataManager::getCurrentEntity(), so meta tags and JSON-LD always describe the same entity
 */
class ApplyMetaTagTemplates implements ObserverInterface
{
    /**
     * @var Manager
     */
    private Manager $metaTagManager;

    /**
     * @var StructuredDataManager
     */
    private StructuredDataManager $structuredDataManager;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var PageConfig
     */
    private PageConfig $pageConfig;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @param Manager $metaTagManager
     * @param StructuredDataManager $structuredDataManager
     * @param LoggerInterface $logger
     * @param PageConfig $pageConfig
     * @param Config $config
     */
    public function __construct(
        Manager $metaTagManager,
        StructuredDataManager $structuredDataManager,
        LoggerInterface $logger,
        PageConfig $pageConfig,
        Config $config
    ) {
        $this->metaTagManager = $metaTagManager;
        $this->structuredDataManager = $structuredDataManager;
        $this->logger = $logger;
        $this->pageConfig = $pageConfig;
        $this->config = $config;
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        // Current store scope (null = current store)
        if (!$this->config->isEnabled() || !$this->config->isMetaTagsEnabled()) {
            return;
        }

        try {
            $entity = $this->structuredDataManager->getCurrentEntity();
            $entityType = $this->getEntityType($entity);

            if (!$entity || !$entityType) {
                return;
            }

            // Apply meta tag templates
            $metaTags = $this->metaTagManager->applyTemplates($entity, $entityType);

            if (empty($metaTags)) {
                return;
            }

            // Apply meta tags to page config
            $this->applyMetaTagsToPageConfig($this->filterByOverrideSettings($metaTags, $entity));
        } catch (\Throwable $e) {
            $this->logger->error('[Defox_SEOSuite] ApplyMetaTagTemplates: ' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }

    /**
     * Drop meta title/description/keywords the store's override settings protect (CR-08)
     *
     * A field is applied when the entity's own value is empty; a non-empty value is replaced only when
     * "Override Only Empty" is No and the per-field override flag is Yes (both need "Enable Meta Tags Override").
     *
     * @param array $metaTags
     * @param mixed $entity
     * @return array
     */
    private function filterByOverrideSettings(array $metaTags, $entity): array
    {
        $existing = [
            'meta_title' => $entity->getData('meta_title'),
            'meta_description' => $entity->getData('meta_description'),
            // product: meta_keyword; category / CMS page: meta_keywords
            'meta_keywords' => $entity->getData('meta_keywords') ?: $entity->getData('meta_keyword'),
        ];

        foreach ($existing as $field => $value) {
            if (!isset($metaTags[$field]) || !is_scalar($value) || trim((string)$value) === '') {
                continue;
            }

            $override = !$this->config->shouldOverrideOnlyEmptyMetaTags() && match ($field) {
                'meta_title' => $this->config->shouldOverrideMetaTitle(),
                'meta_description' => $this->config->shouldOverrideMetaDescription(),
                'meta_keywords' => $this->config->shouldOverrideMetaKeywords(),
            };

            if (!$override) {
                unset($metaTags[$field]);
            }
        }

        return $metaTags;
    }

    /**
     * Apply meta tags to page config
     *
     * @param array $metaTags
     * @return void
     */
    private function applyMetaTagsToPageConfig(array $metaTags): void
    {
        // Apply meta title
        if (isset($metaTags['meta_title']) && !empty($metaTags['meta_title'])) {
            $this->pageConfig->getTitle()->set($metaTags['meta_title']);
        }

        // Apply meta description
        if (isset($metaTags['meta_description']) && !empty($metaTags['meta_description'])) {
            $this->pageConfig->setDescription($metaTags['meta_description']);
        }

        // Apply meta keywords
        if (isset($metaTags['meta_keywords']) && !empty($metaTags['meta_keywords'])) {
            $this->pageConfig->setKeywords($metaTags['meta_keywords']);
        }

        // Apply meta robots
        if (isset($metaTags['meta_robots']) && !empty($metaTags['meta_robots'])) {
            $this->pageConfig->setRobots($metaTags['meta_robots']);
        }

        // Apply Open Graph tags
        if (isset($metaTags['og_title']) && !empty($metaTags['og_title'])) {
            $this->pageConfig->setMetadata('og:title', $metaTags['og_title']);
        }

        if (isset($metaTags['og_description']) && !empty($metaTags['og_description'])) {
            $this->pageConfig->setMetadata('og:description', $metaTags['og_description']);
        }

        if (isset($metaTags['og_type']) && !empty($metaTags['og_type'])) {
            $this->pageConfig->setMetadata('og:type', $metaTags['og_type']);
        }

        if (isset($metaTags['og_image']) && !empty($metaTags['og_image'])) {
            $this->pageConfig->setMetadata('og:image', $metaTags['og_image']);
        }
    }

    /**
     * Get entity type
     *
     * @param mixed $entity
     * @return string|null
     */
    private function getEntityType($entity): ?string
    {
        if ($entity instanceof Product) {
            return 'product';
        }

        if ($entity instanceof Category) {
            return 'category';
        }

        if ($entity instanceof Page) {
            return 'cms_page';
        }

        return null;
    }
}
