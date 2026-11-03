<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki 
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Model\MetaTag;

use Defox\SEOSuite\Api\Data\TemplateInterface as T;
use Defox\SEOSuite\Model\ResourceModel\Template\CollectionFactory as TemplateCollectionFactory;
use Defox\SEOSuite\Model\Template;
use Defox\SEOSuite\Template\VariableProcessorFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Enhanced Meta Tag Manager
 * 
 * Main class responsible for applying meta tag templates to entities with improved performance
 */
class Manager
{
    /**
     * Cache key prefix
     */
    private const CACHE_PREFIX = 'defox_seosuite_metatag_';
    
    /**
     * Cache lifetime (1 hour)
     */
    private const CACHE_LIFETIME = 3600;

    /**
     * Result field => template column, per template type. Templates of every type are merged by priority per field;
     * the type limits which fields a template may contribute (CR-09).
     */
    private const TYPE_FIELDS = [
        Template::TYPE_COMPREHENSIVE => [
            'meta_title' => T::META_TITLE_TEMPLATE,
            'meta_description' => T::META_DESCRIPTION_TEMPLATE,
            'meta_keywords' => T::META_KEYWORDS_TEMPLATE,
            'meta_robots' => T::META_ROBOTS_TEMPLATE,
            'og_title' => T::OG_TITLE_TEMPLATE,
            'og_description' => T::OG_DESCRIPTION_TEMPLATE,
            'og_type' => T::OG_TYPE_TEMPLATE,
            'og_image' => T::OG_IMAGE_TEMPLATE,
        ],
        Template::TYPE_META_TITLE => ['meta_title' => T::META_TITLE_TEMPLATE],
        Template::TYPE_META_DESCRIPTION => ['meta_description' => T::META_DESCRIPTION_TEMPLATE],
        Template::TYPE_META_KEYWORDS => ['meta_keywords' => T::META_KEYWORDS_TEMPLATE],
        Template::TYPE_META_ROBOTS => ['meta_robots' => T::META_ROBOTS_TEMPLATE],
        Template::TYPE_OPEN_GRAPH => [
            'og_title' => T::OG_TITLE_TEMPLATE,
            'og_description' => T::OG_DESCRIPTION_TEMPLATE,
            'og_type' => T::OG_TYPE_TEMPLATE,
            'og_image' => T::OG_IMAGE_TEMPLATE,
        ],
    ];

    /**
     * Fields copied verbatim (no variable processing)
     */
    private const LITERAL_FIELDS = ['meta_robots', 'og_type'];

    /**
     * @var TemplateCollectionFactory
     */
    private TemplateCollectionFactory $templateCollectionFactory;

    /**
     * @var VariableProcessorFactory
     */
    private VariableProcessorFactory $processorFactory;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var CacheInterface
     */
    private CacheInterface $cache;

    /**
     * Active template rows per entity type + store (empty list included)
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $templateCache = [];

    /**
     * @param TemplateCollectionFactory $templateCollectionFactory
     * @param VariableProcessorFactory $processorFactory
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param CacheInterface $cache
     */
    public function __construct(
        TemplateCollectionFactory $templateCollectionFactory,
        VariableProcessorFactory $processorFactory,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        CacheInterface $cache
    ) {
        $this->templateCollectionFactory = $templateCollectionFactory;
        $this->processorFactory = $processorFactory;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    /**
     * Apply meta tag templates to entity
     *
     * Active templates of all types are merged per field: the highest-priority template that yields a non-empty
     * value for a field wins it.
     *
     * @param mixed $entity
     * @param string $entityType
     * @return array
     * @throws \JsonException
     */
    public function applyTemplates($entity, string $entityType): array
    {
        $storeId = (int)$this->storeManager->getStore()->getId();

        $templates = $this->getTemplates($entityType, $storeId);
        if (!$templates) {
            return [];
        }

        // ponytail: per-entity results are not cached — entity saves never cleaned them (CR-32); FPC caches the page
        $result = [];
        $processor = null;

        foreach ($templates as $template) {
            // Check conditions if any
            if (!empty($template[T::CONDITIONS_SERIALIZED])
                && !$this->checkConditions($entity, (string)$template[T::CONDITIONS_SERIALIZED])
            ) {
                continue;
            }

            try {
                foreach (self::TYPE_FIELDS[$template[T::TYPE]] ?? [] as $field => $column) {
                    if (isset($result[$field]) || !isset($template[$column]) || $template[$column] === '') {
                        continue;
                    }

                    if (in_array($field, self::LITERAL_FIELDS, true)) {
                        $value = (string)$template[$column];
                    } else {
                        $processor ??= $this->processorFactory->create($entity);
                        if (!$processor->canProcess($entity)) {
                            return [];
                        }
                        $value = $processor->process((string)$template[$column], $entity);
                    }

                    if (trim($value) !== '') {
                        $result[$field] = $value;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->warning('[Defox_SEOSuite] MetaTag\\Manager: Error processing template', [
                    'template_id' => $template[T::TEMPLATE_ID] ?? null,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $result;
    }

    /**
     * Active templates for entity type + store, highest priority first; cached (empty list too) per request and
     * in the cache for CACHE_LIFETIME (CR-28)
     *
     * @param string $entityType
     * @param int $storeId
     * @return array<int, array<string, mixed>>
     */
    private function getTemplates(string $entityType, int $storeId): array
    {
        $cacheKey = self::CACHE_PREFIX . 'templates_' . $entityType . '_' . $storeId;
        if (isset($this->templateCache[$cacheKey])) {
            return $this->templateCache[$cacheKey];
        }

        $cached = $this->cache->load($cacheKey);
        $templates = $cached !== false ? json_decode((string)$cached, true) : null;

        if (!is_array($templates)) {
            $collection = $this->templateCollectionFactory->create()
                ->addEntityTypeFilter($entityType)
                ->addStoreFilter($storeId)
                ->addActiveFilter()
                ->addPriorityOrder();
            // Deterministic order for equal priorities
            $collection->setOrder(T::TEMPLATE_ID, 'ASC');
            $templates = array_values(array_map(
                static fn ($template): array => $template->getData(),
                $collection->getItems()
            ));
            $this->cache->save(
                json_encode($templates, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                $cacheKey,
                [Template::CACHE_TAG],
                self::CACHE_LIFETIME
            );
            if (!$templates) {
                $this->logDebug('No active templates for entity type', ['entity_type' => $entityType]);
            }
        }

        return $this->templateCache[$cacheKey] = $templates;
    }

    /**
     * Log debug information
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    private function logDebug(string $message, array $context = []): void
    {
        $this->logger->debug('[Defox_SEOSuite] MetaTag\\Manager: ' . $message, $context);
    }

    /**
     * Check if entity matches template conditions
     *
     * @param mixed $entity
     * @param string $conditionsSerialized
     * @return bool
     */
    private function checkConditions(mixed $entity, string $conditionsSerialized): bool
    {
        if (empty($conditionsSerialized)) {
            return true;
        }

        try {
            $conditions = json_decode($conditionsSerialized, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($conditions)) {
                return true;
            }

            foreach ($conditions as $attribute => $expectedValues) {
                if (!method_exists($entity, 'getData')) {
                    continue;
                }

                $entityValue = $entity->getData($attribute);
                
                // Handle different condition types
                if (is_array($expectedValues)) {
                    // Handle range conditions
                    if (isset($expectedValues['min']) || isset($expectedValues['max'])) {
                        $numericValue = (float)$entityValue;
                        if (isset($expectedValues['min']) && $numericValue < $expectedValues['min']) {
                            return false;
                        }
                        if (isset($expectedValues['max']) && $numericValue > $expectedValues['max']) {
                            return false;
                        }
                    } else {
                        // Handle array of values
                        if (!in_array($entityValue, $expectedValues, true)) {
                            return false;
                        }
                    }
                } else {
                    // Handle single value condition
                    if ($entityValue != $expectedValues) {
                        return false;
                    }
                }
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->warning('[Defox_SEOSuite] MetaTag\\Manager: Error checking conditions', [
                'error' => $e->getMessage(),
                'conditions' => $conditionsSerialized
            ]);
            return true; // If conditions are malformed, allow template
        }
    }
}
