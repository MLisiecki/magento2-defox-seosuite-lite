<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Plugin\Catalog\Layer;

use Defox\SEOSuite\Model\Url\Filter as FilterUrl;
use Magento\Catalog\Model\Layer\Filter\Item;

/**
 * Rewrites layered-navigation filter item links into friendly filter URLs.
 *
 * State::getClearUrl needs no plugin: it nulls every filter, so the clean category URL is already correct.
 */
class FilterItemUrl
{
    public function __construct(private readonly FilterUrl $filterUrl)
    {
    }

    public function afterGetUrl(Item $subject, mixed $result): mixed
    {
        return is_string($result) ? $this->filterUrl->toFriendlyUrl($result) : $result;
    }

    public function afterGetRemoveUrl(Item $subject, mixed $result): mixed
    {
        return is_string($result) ? $this->filterUrl->toFriendlyUrl($result) : $result;
    }

    /**
     * Core returns false when the filter has no clear link (no union type: core InterfaceValidator reflects
     * every plugin parameter with ReflectionNamedType::getName()).
     */
    public function afterGetClearLinkUrl(Item $subject, mixed $result): mixed
    {
        return is_string($result) ? $this->filterUrl->toFriendlyUrl($result) : $result;
    }
}
