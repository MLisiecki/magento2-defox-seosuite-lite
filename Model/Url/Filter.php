<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Model\Url;

use Defox\SEOSuite\Helper\Config;
use Defox\SEOSuite\Model\Config\Source\FriendlyUrlPattern;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Filter\TranslitUrl;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;

/**
 * Friendly layered-navigation URLs: `<category path>/<attr><sep><value>[/…]<category suffix>`.
 *
 * Single implementation of both directions so that every built URL parses back to the same filters.
 */
class Filter
{
    /** Same as CategoryUrlPathGenerator::XML_PATH_CATEGORY_URL_SUFFIX / CategoryUrlRewriteGenerator::ENTITY_TYPE */
    private const XML_PATH_CATEGORY_URL_SUFFIX = 'catalog/seo/category_url_suffix';
    private const ENTITY_TYPE_CATEGORY = 'category';
    private const MAX_QUERY_PAIRS = 30;

    /** @var array<string, array<int|string, string>> "storeId/attribute code" => [option id => url key] */
    private array $optionKeys = [];

    /** @var array<string, bool> */
    private array $isCategoryPath = [];

    public function __construct(
        private readonly Config $config,
        private readonly Http $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlFinderInterface $urlFinder,
        private readonly EavConfig $eavConfig,
        private readonly TranslitUrl $translitUrl
    ) {
    }

    /**
     * Outbound: move attribute-option filters of a core layered-nav URL into the path.
     *
     * Acts only when the current request was routed through a category URL rewrite (alias set by the
     * core url-rewrite router or by our FilterRouter). Every other query param stays in the query string.
     */
    public function toFriendlyUrl(string $url): string
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        $alias = $this->request->getAlias(UrlInterface::REWRITE_REQUEST_PATH_ALIAS);
        if (!is_string($alias) || $alias === '' || !$this->isActive($storeId)) {
            return $url;
        }
        $suffix = $this->getCategorySuffix($storeId);
        [$path, $query] = array_pad(explode('?', $url, 2), 2, null);
        if ($query === null
            || !str_ends_with($path, '/' . $alias)
            || ($suffix !== '' && !str_ends_with($alias, $suffix))
            || !($this->isCategoryPath[$alias] ??= (bool)$this->findCategoryRewrites([$alias], $storeId))
        ) {
            return $url;
        }

        $glue = str_contains($query, '&amp;') ? '&amp;' : '&'; // getRemoveUrl() uses _escape
        $pairs = explode($glue, $query);
        // ponytail: bounds attribute lookups for junk query names (one per distinct name, memoized);
        // a filterable-code whitelist loaded once is the upgrade path if real URLs ever need more
        if (count($pairs) > self::MAX_QUERY_PAIRS) {
            return $url;
        }
        $filters = [];
        $rest = [];
        foreach ($pairs as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = isset($filters[$name]) ? null : ($this->getOptionKeys($name, $storeId)[$value] ?? null);
            if ($key === null) {
                $rest[] = $pair;
            } else {
                $filters[$name] = $key;
            }
        }
        if (!$filters) {
            return $url;
        }

        ksort($filters); // one URL per filter set, regardless of click order
        $sep = $this->config->getValueSeparator($storeId);
        $segments = '';
        foreach ($filters as $code => $key) {
            $segments .= '/' . rawurlencode((string)$code) . $sep . rawurlencode($key);
        }

        return substr($path, 0, -strlen($alias)) . substr($alias, 0, strlen($alias) - strlen($suffix))
            . $segments . $suffix . ($rest ? '?' . implode($glue, $rest) : '');
    }

    /**
     * Inbound: resolve a path built by toFriendlyUrl().
     *
     * Returns null unless the path minus trailing filter segments is a category URL rewrite of the current
     * store and every filter segment is `<filterable attribute code><sep><existing option key>`.
     *
     * @return array{request_path: string, target_path: string, filters: array<string, string>}|null
     */
    public function match(string $pathInfo): ?array
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        if (!$this->isActive($storeId)) {
            return null;
        }
        $requestPath = ltrim($pathInfo, '/');
        $suffix = $this->getCategorySuffix($storeId);
        if ($suffix !== '' && !str_ends_with($requestPath, $suffix)) {
            return null;
        }
        $segments = explode('/', substr($requestPath, 0, strlen($requestPath) - strlen($suffix)));
        $sep = $this->config->getValueSeparator($storeId);

        // Walk trailing filter segments; each prefix that remains is a category candidate (longest first).
        $filters = [];
        $candidates = [];
        for ($i = count($segments) - 1; $i > 0; $i--) {
            [$code, $key] = array_pad(explode($sep, $segments[$i], 2), 2, null);
            $optionId = $key === null || isset($filters[$code])
                ? false
                : array_search($key, $this->getOptionKeys($code, $storeId), true);
            if ($optionId === false) {
                break;
            }
            $filters[$code] = (string)$optionId;
            $candidates[implode('/', array_slice($segments, 0, $i)) . $suffix] = $filters;
        }
        if (!$candidates
            // a real rewrite (product, CMS, custom, category) for the full path always wins
            || $this->urlFinder->findOneByData([
                UrlRewrite::REQUEST_PATH => $requestPath,
                UrlRewrite::STORE_ID => $storeId,
            ])
        ) {
            return null;
        }

        $rewrites = $this->findCategoryRewrites(array_map('strval', array_keys($candidates)), $storeId);
        foreach ($candidates as $categoryPath => $candidateFilters) {
            $rewrite = $rewrites[(string)$categoryPath] ?? null;
            if ($rewrite !== null) {
                ksort($candidateFilters);
                return [
                    'request_path' => (string)$categoryPath,
                    'target_path' => (string)$rewrite->getTargetPath(),
                    'filters' => $candidateFilters,
                ];
            }
        }
        return null;
    }

    private function isActive(int $storeId): bool
    {
        return $this->config->isEnabled($storeId)
            && $this->config->areFriendlyUrlsEnabled($storeId)
            && $this->config->getFriendlyUrlPattern($storeId) === FriendlyUrlPattern::PATTERN_PATH;
    }

    private function getCategorySuffix(int $storeId): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_CATEGORY_URL_SUFFIX,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @param string[] $requestPaths
     * @return array<string, UrlRewrite> keyed by request path
     */
    private function findCategoryRewrites(array $requestPaths, int $storeId): array
    {
        $result = [];
        foreach ($this->urlFinder->findAllByData([
            UrlRewrite::REQUEST_PATH => $requestPaths,
            UrlRewrite::STORE_ID => $storeId,
            UrlRewrite::ENTITY_TYPE => self::ENTITY_TYPE_CATEGORY,
            UrlRewrite::REDIRECT_TYPE => 0,
        ]) as $rewrite) {
            $result[(string)$rewrite->getRequestPath()] = $rewrite;
        }
        return $result;
    }

    /**
     * URL keys of a filterable (category layer) option attribute; empty for anything else.
     *
     * "Filterable" is exactly the core category layer list (Layer\Category\FilterableAttributeList: every
     * is_filterable attribute of the store, not per category), so a segment the layer would not apply cannot
     * resolve here. Pages that render no layer at all get NOINDEX (RobotsHandler) or the clean canonical.
     *
     * Key = slug of the store label (core url-key transliteration); empty or colliding slugs, and slugs
     * equal to another option's id-key, fall back to the option id, so keys are unique per attribute.
     *
     * @return array<int|string, string> option id => key
     */
    private function getOptionKeys(string $code, int $storeId): array
    {
        $memoKey = $storeId . '/' . $code;
        if (isset($this->optionKeys[$memoKey])) {
            return $this->optionKeys[$memoKey];
        }
        $keys = [];
        $attribute = preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $code)
            ? $this->eavConfig->getAttribute(Product::ENTITY, $code)
            : null;
        if ($attribute
            && $attribute->getId()
            && $attribute->getAttributeCode() === $code
            && (int)$attribute->getIsFilterable() > 0
            && $attribute->usesSource()
        ) {
            // Table source reads labels for the attribute's store (falls back to the current store when unset)
            $attribute->setStoreId($storeId);
            foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                $value = $option['value'] ?? '';
                if (is_scalar($value) && (string)$value !== '') {
                    $keys[(string)$value] = (string)$this->translitUrl->filter((string)($option['label'] ?? ''));
                }
            }
            do {
                $changed = false;
                $counts = array_count_values($keys);
                foreach ($keys as $id => $key) {
                    if ($key !== (string)$id && ($key === '' || $counts[$key] > 1)) {
                        $keys[$id] = (string)$id;
                        $changed = true;
                    }
                }
            } while ($changed);
        }
        return $this->optionKeys[$memoKey] = $keys;
    }
}
