<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Model\MetaTag;

use Defox\SEOSuite\Model\Template;
use Magento\PageCache\Model\Spi\PageCacheTagsPreprocessorInterface;

/**
 * Adds the meta-template tag to cacheable product/category/CMS pages (registered per action in etc/frontend/di.xml)
 *
 * ponytail: one tag for all entity pages, so any template save/delete purges every such page (templates apply per
 * entity type anyway); a per-entity-type tag is the upgrade if purges get too wide.
 */
class PageCacheTags implements PageCacheTagsPreprocessorInterface
{
    /**
     * @inheritdoc
     */
    public function process(array $tags): array
    {
        $tags[] = Template::CACHE_TAG;
        return $tags;
    }
}
