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
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Select;

/**
 * Base for collection-backed providers (product, category, CMS page)
 *
 * One page = one collection load ordered by entity id, plus a fixed number of batch queries
 * (URL paths, one per alternate store); nothing is queried per entity. Errors propagate to the generator,
 * which then keeps the previously published sitemap.
 */
abstract class AbstractEntityProvider extends AbstractProvider
{
    /**
     * Qualified id column of the collection's main table (ordering, exclusions)
     */
    protected const ID_COLUMN = 'e.entity_id';

    /**
     * Collection of the entities that belong in this store's sitemap (all provider filters applied, no paging)
     *
     * @param int $storeId
     * @return AbstractDb
     */
    abstract protected function createCollection(int $storeId): AbstractDb;

    /**
     * Build the sitemap item for one loaded entity
     *
     * @param DataObject $entity
     * @param string $url
     * @param int $storeId
     * @return SitemapItem
     */
    abstract protected function createItem(DataObject $entity, string $url, int $storeId): SitemapItem;

    /**
     * @inheritDoc
     */
    public function getItems(int $storeId, int $limit = 0, int $offset = 0): array
    {
        $collection = $this->getCollection($storeId);
        $collection->getSelect()->order(static::ID_COLUMN . ' ASC');
        if ($limit > 0) {
            $collection->getSelect()->limit($limit, $offset);
        }

        $entities = $collection->getItems();
        if (!$entities) {
            return [];
        }

        $paths = $this->getPaths($storeId, $entities);
        $alternates = $this->getAlternates($storeId, $entities, $paths);
        $baseUrl = $this->getBaseUrl($storeId);

        $items = [];
        foreach ($entities as $id => $entity) {
            if (!isset($paths[$id])) {
                // URL rewrite vanished between the two queries: keep the page size so paging stays aligned
                $items[] = new SitemapItem($baseUrl, null, null, null, [], [], false);
                continue;
            }
            $item = $this->createItem($entity, $baseUrl . $paths[$id], $storeId);
            $item->setAlternates($alternates[$id] ?? []);
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @inheritDoc
     */
    public function getItemsCount(int $storeId): int
    {
        return (int)$this->getCollection($storeId)->getSize();
    }

    /**
     * Filtered collection minus manual exclusions (defox_seosuite_sitemap_exclusion, store 0 = all stores)
     *
     * @param int $storeId
     * @return AbstractDb
     */
    protected function getCollection(int $storeId): AbstractDb
    {
        $collection = $this->createCollection($storeId);
        $excluded = $this->resourceConnection->getConnection()->select()
            ->from($this->resourceConnection->getTableName('defox_seosuite_sitemap_exclusion'), 'entity_id')
            ->where('entity_type = ?', $this->getCode())
            ->where('store_id IN (?)', [0, $storeId]);
        $collection->getSelect()->where(static::ID_COLUMN . ' NOT IN ?', $excluded); // quote() wraps a Select in ()

        return $collection;
    }

    /**
     * Restrict a catalog collection to entities with a canonical URL rewrite in the store (core sitemap conditions)
     *
     * @param AbstractDb $collection
     * @param int $storeId
     * @return void
     */
    protected function addUrlRewriteFilter(AbstractDb $collection, int $storeId): void
    {
        $collection->getSelect()->where('EXISTS ?', $this->getUrlRewriteSelect($storeId)
            ->reset(Select::COLUMNS)
            ->columns(['ur.url_rewrite_id'])
            ->where('ur.entity_id = ' . static::ID_COLUMN));
    }

    /**
     * Relative URL path per entity id for the given store: canonical url_rewrite.request_path, one query per batch
     *
     * @param int $storeId
     * @param DataObject[] $entities Keyed by entity id
     * @return array<int|string, string>
     */
    protected function getPaths(int $storeId, array $entities): array
    {
        $select = $this->getUrlRewriteSelect($storeId)->where('ur.entity_id IN (?)', array_keys($entities));
        return $this->resourceConnection->getConnection()->fetchPairs($select);
    }

    /**
     * URL paths in another store for those of $entities that belong in that store's sitemap
     *
     * @param int $altStoreId
     * @param DataObject[] $entities Batch of the current store, keyed by entity id
     * @return array<int|string, string> Keyed by the current batch's entity id
     */
    protected function getAlternatePaths(int $altStoreId, array $entities): array
    {
        $collection = $this->getCollection($altStoreId);
        $collection->addFieldToFilter('entity_id', ['in' => array_keys($entities)]);
        $ids = array_keys($collection->getItems());

        return $ids ? $this->getPaths($altStoreId, array_flip($ids)) : [];
    }

    /**
     * Hreflang alternates per entity id; the current store is included (self-reference), single-entry lists dropped
     *
     * @param int $storeId
     * @param DataObject[] $entities
     * @param array<int|string, string> $paths
     * @return array<int|string, array<int, array{lang: string, url: string}>>
     */
    protected function getAlternates(int $storeId, array $entities, array $paths): array
    {
        $result = [];
        foreach ($this->getAlternateStores($storeId) as $altStoreId => $lang) {
            $altPaths = $altStoreId === $storeId ? $paths : $this->getAlternatePaths($altStoreId, $entities);
            $altBaseUrl = $this->getBaseUrl($altStoreId);
            foreach ($altPaths as $id => $path) {
                $result[$id][] = ['lang' => $lang, 'url' => $altBaseUrl . $path];
            }
        }

        return array_filter($result, static fn (array $alternates): bool => count($alternates) > 1);
    }

    /**
     * Hreflang code per store for the current store's website: active stores only, full locale ("en-GB"),
     * first store wins per locale (current store first). Empty when hreflang is off or no other store qualifies.
     *
     * @param int $storeId
     * @return array<int, string>
     */
    protected function getAlternateStores(int $storeId): array
    {
        if (!$this->supportsHreflang() || !$this->config->getValue('defox_seosuite/sitemap/enable_hreflang', $storeId)) {
            return [];
        }

        $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        $stores = $this->storeManager->getStores();
        ksort($stores);
        $stores = [$storeId => $this->storeManager->getStore($storeId)] + $stores;

        $result = [];
        foreach ($stores as $id => $store) {
            if ((int)$store->getWebsiteId() !== $websiteId || !$store->getIsActive()) {
                continue;
            }
            $lang = str_replace('_', '-', $this->config->getLocaleCode((int)$id));
            if ($lang !== '' && !in_array($lang, $result, true)) {
                $result[(int)$id] = $lang;
            }
        }

        return count($result) > 1 ? $result : [];
    }

    /**
     * Canonical (autogenerated, non-redirect, no category path) url_rewrite rows of this entity type in a store
     *
     * @param int $storeId
     * @return Select Columns: entity_id, request_path
     */
    private function getUrlRewriteSelect(int $storeId): Select
    {
        return $this->resourceConnection->getConnection()->select()
            ->from(['ur' => $this->resourceConnection->getTableName('url_rewrite')], ['entity_id', 'request_path'])
            ->where('ur.entity_type = ?', $this->getCode())
            ->where('ur.store_id = ?', $storeId)
            ->where('ur.is_autogenerated = 1')
            ->where('ur.redirect_type = 0')
            ->where('ur.metadata IS NULL');
    }
}
