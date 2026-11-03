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

/**
 * Additional links provider for sitemap
 *
 * Provides custom links from the "Custom Links" setting and the defox_seosuite_sitemap_additional_links table.
 * Only links on the store's own host are accepted.
 */
class AdditionalLinksProvider extends AbstractProvider
{
    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'additional_links';
    }

    /**
     * @inheritDoc
     */
    public function supportsHreflang(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getItems(int $storeId, int $limit = 0, int $offset = 0): array
    {
        $items = $this->getAllItems($storeId);
        return $limit > 0 ? array_slice($items, $offset, $limit) : array_slice($items, $offset);
    }

    /**
     * @inheritDoc
     */
    public function getItemsCount(int $storeId): int
    {
        return count($this->getAllItems($storeId));
    }

    /**
     * All valid links, config lines first, then table rows by link_id
     *
     * ponytail: built in full per call; fine for hand-maintained lists, page in SQL if the table ever grows large.
     *
     * @param int $storeId
     * @return SitemapItem[]
     */
    private function getAllItems(int $storeId): array
    {
        $baseUrl = $this->getBaseUrl($storeId);
        $items = [];

        $customLinks = (string)$this->config->getValue('defox_seosuite/sitemap/additional_links/custom_links', $storeId);
        foreach (preg_split('/\R/', $customLinks) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $item = $this->createItem($baseUrl, $parts[0], $parts[1] ?? null, $parts[2] ?? null, null, $storeId);
            if ($item) {
                $items[] = $item;
            }
        }

        foreach ($this->getAdditionalLinks($storeId) as $link) {
            $item = $this->createItem(
                $baseUrl,
                (string)$link['url'],
                $link['priority'] ?? null,
                $link['changefreq'] ?? null,
                $link['updated_at'] ?? null,
                $storeId
            );
            if ($item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Sitemap item for one link, or null (logged) when the link is not a same-host http(s) URL
     *
     * @param string $baseUrl
     * @param string $link Relative path or absolute URL
     * @param mixed $priority
     * @param mixed $changefreq
     * @param string|null $updatedAt
     * @param int $storeId
     * @return SitemapItem|null
     */
    private function createItem(
        string $baseUrl,
        string $link,
        mixed $priority,
        mixed $changefreq,
        ?string $updatedAt,
        int $storeId
    ): ?SitemapItem {
        $url = $this->resolveUrl($baseUrl, $link);
        if ($url === null) {
            $this->logger->warning(sprintf('Sitemap additional link rejected for store %d: "%s"', $storeId, $link));
            return null;
        }

        $item = new SitemapItem($url);
        $item->setPriority(
            is_numeric($priority) && $priority >= 0 && $priority <= 1
                ? (float)$priority
                : $this->getDefaultPriority($storeId)
        );
        $item->setChangefreq(
            in_array($changefreq, self::CHANGEFREQS, true) ? $changefreq : $this->getDefaultChangefreq($storeId)
        );
        if ($updatedAt) {
            $item->setLastmod(date('Y-m-d', strtotime($updatedAt)));
        }

        return $item;
    }

    /**
     * Absolute URL for a relative path ("about-us", "/blog?p=2") or a same-host http(s) URL; null otherwise
     * (other hosts, protocol-relative "//host", javascript:/mailto:/data: and any other scheme, whitespace)
     *
     * @param string $baseUrl Store base URL with trailing slash
     * @param string $link
     * @return string|null
     */
    private function resolveUrl(string $baseUrl, string $link): ?string
    {
        if ($link === '' || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $link)) {
            return null;
        }

        $parts = parse_url($link);
        if ($parts === false) {
            return null;
        }

        if (!isset($parts['scheme']) && !isset($parts['host'])) {
            return $baseUrl . ltrim($link, '/');
        }

        $base = parse_url($baseUrl);
        $sameOrigin = in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && !isset($parts['user'])
            && strcasecmp($parts['host'] ?? '', $base['host'] ?? '') === 0
            && ($parts['port'] ?? null) === ($base['port'] ?? null);

        return $sameOrigin ? $link : null;
    }

    /**
     * Get additional links from database
     *
     * @param int $storeId
     * @return array
     */
    private function getAdditionalLinks(int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('defox_seosuite_sitemap_additional_links');

        $select = $connection->select()
            ->from($table)
            ->where('store_id IN (?)', [0, $storeId])
            ->where('is_active = ?', 1)
            ->order('link_id ASC');

        return $connection->fetchAll($select);
    }

    /**
     * Get additional links grouped by group code for HTML sitemap
     *
     * @param int $storeId
     * @return array
     */
    public function getGroupedLinks(int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('defox_seosuite_sitemap_additional_links');

        $select = $connection->select()
            ->from($table)
            ->where('store_id IN (?)', [0, $storeId])
            ->where('is_active = ?', 1)
            ->order(['group_code ASC', 'title ASC']);

        $links = $connection->fetchAll($select);

        $grouped = [];
        foreach ($links as $link) {
            $groupCode = $link['group_code'] ?: 'other';
            if (!isset($grouped[$groupCode])) {
                $grouped[$groupCode] = [];
            }
            $grouped[$groupCode][] = $link;
        }

        return $grouped;
    }
}
