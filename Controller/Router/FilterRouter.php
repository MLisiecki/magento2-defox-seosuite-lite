<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki 
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Controller\Router;

use Defox\SEOSuite\Model\Url\Filter as FilterUrl;
use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Magento\Framework\UrlInterface;

/**
 * Routes friendly filter URLs (`women/tops-women/color-red.html`) to the category controller.
 *
 * Runs before the core url-rewrite router; returns null (request untouched) for anything that is not
 * a category URL rewrite followed by valid filterable-attribute/option segments.
 */
class FilterRouter implements RouterInterface
{
    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly FilterUrl $filterUrl
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        if (!$request instanceof Http) {
            return null;
        }
        $match = $this->filterUrl->match((string)$request->getPathInfo());
        if ($match === null) {
            return null;
        }

        // Validated pairs only: filterable attribute code => existing option id (query, so `_current` URLs keep them).
        foreach ($match['filters'] as $code => $optionId) {
            $request->setQueryValue($code, $optionId);
        }
        // Same forward as Magento\UrlRewrite\Controller\Router::match() for a category rewrite.
        $request->setAlias(UrlInterface::REWRITE_REQUEST_PATH_ALIAS, $match['request_path']);
        $request->setPathInfo('/' . $match['target_path']);

        return $this->actionFactory->create(Forward::class);
    }
}
