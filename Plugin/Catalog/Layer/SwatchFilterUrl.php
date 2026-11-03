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
use Magento\Swatches\Block\LayeredNavigation\RenderLayered;

/**
 * Rewrites swatch layered-navigation links into friendly filter URLs.
 *
 * Magento_Swatches is optional (composer suggest): when it is absent the plugged type does not exist and
 * this class is never loaded (same pattern as core module-weee etc/frontend/di.xml).
 */
class SwatchFilterUrl
{
    public function __construct(private readonly FilterUrl $filterUrl)
    {
    }

    public function afterBuildUrl(RenderLayered $subject, mixed $result): mixed
    {
        return is_string($result) ? $this->filterUrl->toFriendlyUrl($result) : $result;
    }
}
