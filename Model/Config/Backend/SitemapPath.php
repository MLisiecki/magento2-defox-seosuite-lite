<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Model\Config\Backend;

use Defox\SEOSuite\Helper\Config;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Sitemap output path (relative to pub/): normalised and validated by Config::normalizeSitemapPath(),
 * the same rules every reader applies
 */
class SitemapPath extends Value
{
    /**
     * @var Config
     */
    private Config $sitemapConfig;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param Config $sitemapConfig
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        Config $sitemapConfig,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->sitemapConfig = $sitemapConfig;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Normalise to "dir/sub/" (or "" for pub/ itself); reject absolute paths, ".." and paths core does not allow
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        // An untouched legacy value must not block saving the rest of the section
        if (!$this->isValueChanged()) {
            return parent::beforeSave();
        }

        $this->setValue($this->sitemapConfig->normalizeSitemapPath((string)$this->getValue()));

        return parent::beforeSave();
    }
}
